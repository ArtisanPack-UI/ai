<?php

/**
 * Shared AI feature-response handler for HTTP + Livewire consumers.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Concerns;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Exceptions\BudgetExceededException;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Ai\Support\AiFeatureOutcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs an AI agent callable behind the exception ladder every trigger
 * surface shares, and computes the per-feature enabled map every surface
 * exposes.
 *
 * Both concerns were copy-pasted across each consumer of the AI foundation
 * — the JSON controller and Livewire component in `cms-framework`,
 * `visual-editor`, and any future host. This trait makes the ai package the
 * one source of truth for the exception-to-response mapping so renaming an
 * error code or adding a fifth failure layer is a single edit here.
 *
 * A trait rather than an abstract base so consumers can compose it onto a
 * framework-specific parent (`Illuminate\Routing\Controller`,
 * `Livewire\Component`, ...).
 *
 * ```php
 * class AiController extends Controller
 * {
 *     use HandlesAiFeatureResponses;
 *
 *     public function altText( AltTextRequest $request ): JsonResponse
 *     {
 *         $outcome = $this->handleAiFeature(
 *             'ai.alt_text',
 *             fn () => AltTextGenerationAgent::for( $request->validated()['image'] )->run(),
 *         );
 *
 *         if ( $outcome->succeeded ) {
 *             return new JsonResponse( [ 'feature' => $outcome->feature, 'output' => $outcome->output ] );
 *         }
 *
 *         return new JsonResponse( [
 *             'feature' => $outcome->feature,
 *             'error'   => $outcome->errorCode,
 *             'message' => $outcome->message,
 *         ], $outcome->status );
 *     }
 * }
 * ```
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
trait HandlesAiFeatureResponses
{
    /**
     * Run an agent callable and normalize its result into an outcome.
     *
     * Maps the five AI exception layers onto the shared
     * `(status, errorCode, statusSlug, message)` tuple; any other throwable
     * is logged (via {@see aiFeatureLogMessage()}) and reported as a generic
     * `internal_error` so a raw exception message never reaches the client.
     *
     * The agent is passed as a callable rather than a built agent so its
     * construction runs *inside* the try: hosts may bind a subclass over an
     * agent, so `for()` is itself a real throw site that must be caught here.
     *
     * @since 1.2.0
     *
     * @param  string           $featureKey  Feature key the call runs under.
     * @param  callable(): mixed  $callback  Runs the agent and returns its shaped output.
     *
     * @return AiFeatureOutcome
     */
    protected function handleAiFeature( string $featureKey, callable $callback ): AiFeatureOutcome
    {
        try {
            return AiFeatureOutcome::success( $featureKey, $callback() );
        } catch ( FeatureDisabledException $e ) {
            return AiFeatureOutcome::failed( $featureKey, 403, 'feature_disabled', 'disabled', $e->getMessage() );
        } catch ( MissingCredentialsException $e ) {
            return AiFeatureOutcome::failed( $featureKey, 503, 'missing_credentials', 'missing-credentials', $e->getMessage() );
        } catch ( FeatureError $e ) {
            return AiFeatureOutcome::failed( $featureKey, 422, 'invalid_input', 'invalid-input', $e->getMessage() );
        } catch ( BudgetExceededException $e ) {
            return AiFeatureOutcome::failed(
                $featureKey,
                429,
                'budget_exceeded',
                'budget-exceeded',
                (string) __( 'The AI monthly budget has been reached. Please try again later.' ),
            );
        } catch ( Throwable $e ) {
            Log::error( $this->aiFeatureLogMessage(), [
                'feature' => $featureKey,
                'error'   => $e->getMessage(),
            ] );

            return AiFeatureOutcome::failed(
                $featureKey,
                500,
                'internal_error',
                'error',
                (string) __( 'Unexpected error running AI feature.' ),
            );
        }
    }

    /**
     * Compute the enabled state of each of the given feature keys.
     *
     * A key is reported enabled only when it is registered *and* toggled on;
     * an unregistered or toggled-off key reports false, so the map fails
     * closed for features a host never registered.
     *
     * @since 1.2.0
     *
     * @param  iterable<string>  $featureKeys  Feature keys to resolve.
     *
     * @return array<string, bool> Map of feature key to enabled state.
     */
    protected function aiFeatureStateMap( iterable $featureKeys ): array
    {
        /** @var FeatureRegistry $registry */
        $registry = app( FeatureRegistry::class );

        $state = [];

        foreach ( $featureKeys as $key ) {
            $state[ $key ] = null !== $registry->get( $key ) && $registry->isToggleOn( $key );
        }

        return $state;
    }

    /**
     * The message logged when an agent call throws an unexpected error.
     *
     * Consumers override this to tag the log line with their package and
     * surface (e.g. `cms-framework AI API call failed`). The logged context
     * shape — `['feature' => ..., 'error' => ...]` — is fixed here.
     *
     * @since 1.2.0
     *
     * @return string
     */
    protected function aiFeatureLogMessage(): string
    {
        return 'AI feature call failed';
    }
}
