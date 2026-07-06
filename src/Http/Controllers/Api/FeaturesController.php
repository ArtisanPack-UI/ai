<?php

/**
 * AI features JSON API controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Http\Controllers\Api;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Registry\FeatureDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists registered features and flips their enabled state.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class FeaturesController extends AbstractAdminController
{
    /**
     * GET /features — return the full registry listing with enabled state.
     *
     * @since 1.0.0
     *
     * @param  FeatureRegistry  $registry  Feature registry.
     *
     * @return JsonResponse
     */
    public function index( FeatureRegistry $registry ): JsonResponse
    {
        $this->authorizeAdmin();

        $features = $registry->all()
            ->map( fn ( FeatureDefinition $definition ): array => [
                'key'         => $definition->featureKey,
                'package'     => $definition->package,
                'label'       => $definition->label,
                'description' => $definition->description,
                'enabled'     => $registry->isToggleOn( $definition->featureKey ),
            ] )
            ->all();

        return new JsonResponse( [
            'features' => $features,
        ] );
    }

    /**
     * POST /features/{key}/toggle — enable or disable a single feature.
     *
     * The request body may include `{"enabled": true|false}` to set an
     * explicit state; omitting it flips the current state. Returns 404
     * when the feature key isn't registered.
     *
     * @since 1.0.0
     *
     * @param  Request          $request     Incoming HTTP request.
     * @param  FeatureRegistry  $registry    Feature registry.
     * @param  string           $featureKey  Feature key to toggle.
     *
     * @return JsonResponse
     */
    public function toggle( Request $request, FeatureRegistry $registry, string $featureKey ): JsonResponse
    {
        $this->authorizeAdmin();

        $definition = $registry->get( $featureKey );

        if ( null === $definition ) {
            return new JsonResponse( [
                'message' => __( 'Feature not found.' ),
            ], 404 );
        }

        $validated = $request->validate( [
            'enabled' => [ 'nullable', 'boolean' ],
        ] );

        $target = array_key_exists( 'enabled', $validated ) && null !== $validated['enabled']
            ? (bool) $validated['enabled']
            : ! $registry->isToggleOn( $featureKey );

        if ( $target ) {
            $registry->enable( $featureKey );
        } else {
            $registry->disable( $featureKey );
        }

        return new JsonResponse( [
            'feature' => [
                'key'     => $definition->featureKey,
                'package' => $definition->package,
                'enabled' => $target,
            ],
        ] );
    }
}
