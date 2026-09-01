<?php

declare( strict_types=1 );

/**
 * Executable record of the laravel/ai capabilities this package's consumers
 * depend on (#52). These are the features Keystone's AI integration blocks
 * on — conversation persistence (§8.3) and human-in-the-loop tool approval
 * (§8.8). If the `laravel/ai` constraint is ever relaxed below a version
 * that ships them, these assertions fail loudly instead of the breakage
 * surfacing downstream in Keystone.
 *
 * Human tool approval (`Approvable` / `Decisions` / `tool_approval_request`)
 * landed upstream in laravel/ai v0.10.0; conversations predate it. The
 * composer constraint is pinned to `^0.11.0`.
 */

it( 'exposes laravel/ai conversation persistence concerns', function (): void {
    expect( trait_exists( 'Laravel\\Ai\\Concerns\\RemembersConversations' ) )->toBeTrue();
    expect( trait_exists( 'Laravel\\Ai\\Concerns\\HasConversations' ) )->toBeTrue();
} );

it( 'exposes a continue() entry point for resuming a stored conversation', function (): void {
    expect( method_exists( 'Laravel\\Ai\\Concerns\\RemembersConversations', 'continue' ) )->toBeTrue();
} );

it( 'exposes the human tool-approval surface', function (): void {
    expect( interface_exists( 'Laravel\\Ai\\Contracts\\Approvable' ) )->toBeTrue();
    expect( class_exists( 'Laravel\\Ai\\Approvals\\Decisions' ) )->toBeTrue();
    expect( class_exists( 'Laravel\\Ai\\Streaming\\Events\\ToolApprovalRequest' ) )->toBeTrue();
} );

it( 'emits the tool_approval_request streaming event type', function (): void {
    $event  = new ReflectionClass( 'Laravel\\Ai\\Streaming\\Events\\ToolApprovalRequest' );
    $source = file_get_contents( $event->getFileName() );

    expect( $source )->toContain( 'tool_approval_request' );
} );

it( 'accepts a Decisions payload on the prompt() entry point for approval continuation', function (): void {
    $method = new ReflectionMethod( 'Laravel\\Ai\\Promptable', 'prompt' );
    $type   = (string) $method->getParameters()[0]->getType();

    expect( $type )->toContain( 'Decisions' );
} );
