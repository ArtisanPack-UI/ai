<?php

/**
 * Create the ai_usage_events table.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
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
        Schema::create( 'ai_usage_events', function ( Blueprint $table ): void {
            $table->id();
            $table->string( 'feature_key' )->index();
            $table->string( 'package' )->index();
            $table->string( 'provider' )->default( '' );
            $table->string( 'model' );
            $table->unsignedInteger( 'input_tokens' )->default( 0 );
            $table->unsignedInteger( 'output_tokens' )->default( 0 );
            $table->decimal( 'estimated_cost_usd', 12, 6 )->default( 0 );
            $table->boolean( 'cache_hit' )->default( false );
            $table->timestamp( 'created_at' )->index();

            $table->index( [ 'feature_key', 'created_at' ], 'ai_usage_feature_created_idx' );
            $table->index( [ 'package', 'created_at' ], 'ai_usage_package_created_idx' );
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
        Schema::dropIfExists( 'ai_usage_events' );
    }
};
