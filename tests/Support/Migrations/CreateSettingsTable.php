<?php

/**
 * Test-only migration mirroring cms-framework's `settings` table.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the minimal `settings` table shape the credential store expects.
 *
 * Kept in the test suite so the ai package can validate the encrypted
 * storage layer without a hard dependency on cms-framework.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class CreateSettingsTable extends Migration
{
    /**
     * Run the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'settings', function ( Blueprint $table ): void {
            $table->string( 'key' )->primary();
            $table->text( 'value' )->nullable();
            $table->string( 'type' )->default( 'string' );
            $table->timestamps();
        } );
    }

    /**
     * Reverse the migration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'settings' );
    }
}
