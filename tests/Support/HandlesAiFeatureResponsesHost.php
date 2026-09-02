<?php

/**
 * Test-only host exercising the HandlesAiFeatureResponses trait.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ai\Concerns\HandlesAiFeatureResponses;
use ArtisanPackUI\Ai\Support\AiFeatureOutcome;

/**
 * Plain (non-Livewire, non-controller) class that composes the shared trait
 * and re-exposes its protected methods so the exception ladder and the
 * feature-state walk can be asserted directly, independent of any transport.
 *
 * The overridden {@see aiFeatureLogMessage()} lets the Throwable branch's
 * logging be asserted against a known label.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
class HandlesAiFeatureResponsesHost
{
    use HandlesAiFeatureResponses;

    /**
     * Log label this host tags unexpected-error log lines with.
     *
     * @since 1.2.0
     *
     * @var string
     */
    public const LOG_MESSAGE = 'test host AI call failed';

    /**
     * Run an agent callable through the shared handler.
     *
     * @since 1.2.0
     *
     * @param  string           $featureKey  Feature key the call runs under.
     * @param  callable(): mixed  $callback  Runs the agent and returns its output.
     *
     * @return AiFeatureOutcome
     */
    public function run( string $featureKey, callable $callback ): AiFeatureOutcome
    {
        return $this->handleAiFeature( $featureKey, $callback );
    }

    /**
     * Compute the enabled-state map for the given feature keys.
     *
     * @since 1.2.0
     *
     * @param  iterable<string>  $featureKeys  Feature keys to resolve.
     *
     * @return array<string, bool>
     */
    public function stateMap( iterable $featureKeys ): array
    {
        return $this->aiFeatureStateMap( $featureKeys );
    }

    /**
     * {@inheritDoc}
     */
    protected function aiFeatureLogMessage(): string
    {
        return self::LOG_MESSAGE;
    }
}
