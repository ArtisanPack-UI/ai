<?php

/**
 * Main Ai class.
 *
 * Entry point for the shared AI foundation, accessed via the `ai()` helper
 * function or the Ai facade. Foundation classes (provider resolution,
 * request builders, tool registries, etc.) will hang off of this class as
 * the RFC is implemented.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai;

use ArtisanPackUI\Ai\Testing\AiFake;

/**
 * Main Ai class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
class Ai
{
    /**
     * Active test-double, or null when the real agent pipeline is in effect.
     *
     * @since 1.2.0
     *
     * @var AiFake|null
     */
    protected ?AiFake $fake = null;

    /**
     * Swap the agent pipeline for a deterministic test-double.
     *
     * While a fake is active, every {@see Agents\ArtisanPackAgent::run()}
     * returns a queued response and records the run instead of resolving
     * credentials or calling a provider. Returns the fake so tests can queue
     * responses and make assertions off the same instance.
     *
     * @since 1.2.0
     *
     * @param  array<class-string, array<string, mixed>>  $responses  Map of agent class to default output.
     *
     * @return AiFake
     */
    public function fake( array $responses = [] ): AiFake
    {
        return $this->fake = new AiFake( $responses );
    }

    /**
     * Whether a test-double is currently installed.
     *
     * @since 1.2.0
     *
     * @return bool
     */
    public function isFaking(): bool
    {
        return $this->fake instanceof AiFake;
    }

    /**
     * The active test-double, or null when none is installed.
     *
     * @since 1.2.0
     *
     * @return AiFake|null
     */
    public function getFake(): ?AiFake
    {
        return $this->fake;
    }
}
