<?php

/**
 * Base controller for the AI admin JSON API.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Http\Controllers\Api;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Authorises every JSON API request against the ability configured under
 * `artisanpack.ai.api.ability`. Kept as a plain controller base rather than
 * a middleware so downstream apps that route around the package's route
 * loader still benefit from the check when they extend these controllers.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
abstract class AbstractAdminController extends Controller
{
    /**
     * Authorise the request or throw a 403 JSON response.
     *
     * The ability is looked up per-request so an app that dynamically
     * (re)binds the ability at runtime is respected.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function authorizeAdmin(): void
    {
        $ability = (string) config( 'artisanpack.ai.api.ability', 'manage_ai_settings' );

        /** @var Gate $gate */
        $gate = app( Gate::class );

        if ( $gate->allows( $ability ) ) {
            return;
        }

        throw new HttpResponseException(
            new JsonResponse(
                [
                    'message' => __( 'You are not authorised to manage AI settings.' ),
                ],
                403,
            ),
        );
    }
}
