<?php

declare( strict_types=1 );

use ArtisanPackUI\Ai\Repositories\AiUsageRepository;

it( 'emits driver-appropriate bucket SQL for sqlite, mysql/mariadb, and pgsql', function (): void {
    $repo       = app( AiUsageRepository::class );
    $reflection = new ReflectionMethod( AiUsageRepository::class, 'bucketExpression' );

    // Default connection in Testbench is sqlite.
    expect( $reflection->invoke( $repo, 'day' ) )->toBe( "strftime('%Y-%m-%d', created_at)" );
    expect( $reflection->invoke( $repo, 'week' ) )->toBe( "strftime('%Y-%W', created_at)" );
    expect( $reflection->invoke( $repo, 'month' ) )->toBe( "strftime('%Y-%m', created_at)" );

    // MySQL/MariaDB dialect via a swapped connection.
    config( [
        'database.connections.mysql_test' => [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'x',
            'username' => 'x',
            'password' => 'x',
        ],
    ] );
    $mysqlRepo       = new AiUsageRepository(
        tap( app( Illuminate\Database\ConnectionResolverInterface::class ), function ( $r ): void {
            $r->setDefaultConnection( 'mysql_test' );
        } ),
    );
    $mysqlReflection = new ReflectionMethod( AiUsageRepository::class, 'bucketExpression' );

    expect( $mysqlReflection->invoke( $mysqlRepo, 'day' ) )->toBe( "DATE_FORMAT(created_at, '%Y-%m-%d')" );
    expect( $mysqlReflection->invoke( $mysqlRepo, 'week' ) )->toBe( "DATE_FORMAT(created_at, '%Y-%u')" );
    expect( $mysqlReflection->invoke( $mysqlRepo, 'month' ) )->toBe( "DATE_FORMAT(created_at, '%Y-%m')" );

    // Restore.
    app( Illuminate\Database\ConnectionResolverInterface::class )->setDefaultConnection( 'testbench' );
} );
