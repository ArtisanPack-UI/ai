<?php

declare( strict_types=1 );

use Livewire\Livewire;
use Tests\Support\InteractsWithAiFeatureComponent;

beforeEach( function (): void {
    if ( ! class_exists( Livewire::class ) ) {
        $this->markTestSkipped( 'livewire/livewire is not installed.' );
    }
} );

it( 'runs the callback and clears loading and error on success', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'succeed' )
        ->assertSet( 'result', 'ok' )
        ->assertSet( 'error', null )
        ->assertSet( 'isLoading', false )
        ->assertSee( 'ok' );
} );

it( 'maps a disabled feature onto a user-facing error', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'fail', 'disabled' )
        ->assertSet( 'error', 'This AI feature is disabled.' )
        ->assertSet( 'isLoading', false );
} );

it( 'maps missing credentials onto a user-facing error', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'fail', 'credentials' )
        ->assertSet( 'error', 'AI credentials are not configured.' )
        ->assertSet( 'isLoading', false );
} );

it( 'passes a FeatureError message straight through', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'fail', 'feature' )
        ->assertSet( 'error', 'A domain-specific failure reason.' )
        ->assertSet( 'isLoading', false );
} );

it( 'maps any other throwable onto a generic error', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'fail', 'generic' )
        ->assertSet( 'error', 'The AI agent could not complete this request.' )
        ->assertSet( 'isLoading', false );
} );

it( 'resets a prior error before the next run', function (): void {
    Livewire::test( InteractsWithAiFeatureComponent::class )
        ->call( 'fail', 'disabled' )
        ->assertSet( 'error', 'This AI feature is disabled.' )
        ->call( 'succeed' )
        ->assertSet( 'error', null )
        ->assertSet( 'result', 'ok' );
} );
