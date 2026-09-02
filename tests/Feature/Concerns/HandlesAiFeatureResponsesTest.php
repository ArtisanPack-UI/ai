<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Ai\Support\AiFeatureOutcome;
use Illuminate\Support\Facades\Log;
use Tests\Support\HandlesAiFeatureResponsesHost;

it( 'wraps a successful agent call in a success outcome and logs nothing', function (): void {
    Log::spy();

    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.summarize',
        fn (): array => [ 'summary' => 'done' ],
    );

    expect( $outcome )->toBeInstanceOf( AiFeatureOutcome::class )
        ->and( $outcome->succeeded )->toBeTrue()
        ->and( $outcome->feature )->toBe( 'ai.summarize' )
        ->and( $outcome->output )->toBe( [ 'summary' => 'done' ] )
        ->and( $outcome->status )->toBe( 200 )
        ->and( $outcome->statusSlug )->toBe( 'success' )
        ->and( $outcome->errorCode )->toBeNull()
        ->and( $outcome->message )->toBeNull();

    Log::shouldNotHaveReceived( 'error' );
} );

it( 'maps each handled AI exception onto the normalized tuple, passing its message through', function (
    Throwable $exception,
    int $status,
    string $errorCode,
    string $statusSlug,
    string $message,
): void {
    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.alt_text',
        fn () => throw $exception,
    );

    expect( $outcome->succeeded )->toBeFalse()
        ->and( $outcome->feature )->toBe( 'ai.alt_text' )
        ->and( $outcome->output )->toBeNull()
        ->and( $outcome->status )->toBe( $status )
        ->and( $outcome->errorCode )->toBe( $errorCode )
        ->and( $outcome->statusSlug )->toBe( $statusSlug )
        ->and( $outcome->message )->toBe( $message );
} )->with( [
    'feature disabled' => [
        fn () => FeatureDisabledException::forFeature( 'ai.alt_text' ),
        403,
        'feature_disabled',
        'disabled',
        'AI feature "ai.alt_text" is disabled.',
    ],
    'missing credentials' => [
        fn () => MissingCredentialsException::forFeature( 'ai.alt_text' ),
        503,
        'missing_credentials',
        'missing-credentials',
        'No AI credentials configured for feature "ai.alt_text".',
    ],
    'domain feature error' => [
        fn () => FeatureError::forFeature( 'ai.alt_text', 'unreadable image' ),
        422,
        'invalid_input',
        'invalid-input',
        'AI feature "ai.alt_text" could not run: unreadable image',
    ],
] );

it( 'maps any other throwable onto a generic internal error without leaking its message', function (): void {
    Log::spy();

    // The agent runs as a closure, so a throw from anywhere in it — including
    // a host-bound subclass's `for()` construction — is caught here rather
    // than escaping the handler.
    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.summarize',
        fn () => throw new RuntimeException( 'raw provider stack trace' ),
    );

    expect( $outcome->succeeded )->toBeFalse()
        ->and( $outcome->status )->toBe( 500 )
        ->and( $outcome->errorCode )->toBe( 'internal_error' )
        ->and( $outcome->statusSlug )->toBe( 'error' )
        ->and( $outcome->message )->toBe( 'Unexpected error running AI feature.' )
        ->and( $outcome->message )->not->toContain( 'raw provider stack trace' );
} );

it( 'logs the raw error under the consumer log label in the fixed context shape', function (): void {
    Log::spy();

    ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.summarize',
        fn () => throw new RuntimeException( 'raw provider stack trace' ),
    );

    Log::shouldHaveReceived( 'error' )
        ->once()
        ->withArgs( function ( string $message, array $context ): bool {
            return HandlesAiFeatureResponsesHost::LOG_MESSAGE === $message
                && 'ai.summarize' === $context['feature']
                && 'raw provider stack trace' === $context['error']
                && [ 'feature', 'error' ] === array_keys( $context );
        } );
} );

it( 'reports a feature enabled only when it is both registered and toggled on', function (): void {
    /** @var FeatureRegistry $registry */
    $registry = app( FeatureRegistry::class );

    $registry->register( 'concern.enabled', ConcernAgentStub::class );
    $registry->register( 'concern.toggled_off', ConcernAgentStub::class );
    $registry->disable( 'concern.toggled_off' );

    $map = ( new HandlesAiFeatureResponsesHost() )->stateMap( [
        'concern.enabled',
        'concern.toggled_off',
        'concern.unregistered',
    ] );

    expect( $map )->toBe( [
        'concern.enabled'      => true,
        'concern.toggled_off'  => false,
        'concern.unregistered' => false,
    ] );
} );

it( 'returns an empty map for no feature keys', function (): void {
    expect( ( new HandlesAiFeatureResponsesHost() )->stateMap( [] ) )->toBe( [] );
} );

class ConcernAgentStub
{
}
