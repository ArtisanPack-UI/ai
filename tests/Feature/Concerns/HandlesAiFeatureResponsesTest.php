<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\FeatureError;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Ai\Support\AiFeatureOutcome;
use Illuminate\Support\Facades\Log;
use Tests\Support\HandlesAiFeatureResponsesHost;

it( 'wraps a successful agent call in a success outcome', function (): void {
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
} );

it( 'maps the four AI exception layers onto the normalized tuple', function (
    string $type,
    int $status,
    string $errorCode,
    string $statusSlug,
): void {
    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.alt_text',
        function () use ( $type ): void {
            throw match ( $type ) {
                'disabled'    => FeatureDisabledException::forFeature( 'ai.alt_text' ),
                'credentials' => MissingCredentialsException::forFeature( 'ai.alt_text' ),
                'feature'     => FeatureError::forFeature( 'ai.alt_text', 'unreadable image' ),
            };
        },
    );

    expect( $outcome->succeeded )->toBeFalse()
        ->and( $outcome->feature )->toBe( 'ai.alt_text' )
        ->and( $outcome->output )->toBeNull()
        ->and( $outcome->status )->toBe( $status )
        ->and( $outcome->errorCode )->toBe( $errorCode )
        ->and( $outcome->statusSlug )->toBe( $statusSlug );
} )->with( [
    'feature disabled'     => [ 'disabled', 403, 'feature_disabled', 'disabled' ],
    'missing credentials'  => [ 'credentials', 503, 'missing_credentials', 'missing-credentials' ],
    'domain feature error' => [ 'feature', 422, 'invalid_input', 'invalid-input' ],
] );

it( 'passes the domain exception message straight through on a handled failure', function (): void {
    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.alt_text',
        fn () => throw FeatureError::forFeature( 'ai.alt_text', 'unreadable image' ),
    );

    expect( $outcome->message )->toBe( 'AI feature "ai.alt_text" could not run: unreadable image' );
} );

it( 'maps any other throwable onto a generic internal error without leaking its message', function (): void {
    Log::spy();

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

it( 'runs agent construction inside the handler so a throwing factory is caught', function (): void {
    Log::spy();

    // A host that binds a subclass with unresolvable constructor deps makes
    // `for()` itself a throw site. The callable defers construction into the
    // try, so the failure becomes an internal_error rather than escaping.
    $outcome = ( new HandlesAiFeatureResponsesHost() )->run(
        'ai.alt_text',
        fn () => throw new LogicException( 'container could not resolve bound agent' ),
    );

    expect( $outcome->succeeded )->toBeFalse()
        ->and( $outcome->errorCode )->toBe( 'internal_error' );
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
