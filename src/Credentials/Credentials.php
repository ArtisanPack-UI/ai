<?php

/**
 * BYOK credential value object.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Credentials;

/**
 * Immutable BYOK credential set for an AI provider.
 *
 * Instances of this class must never be logged or serialised to a browser
 * client. Consumers that echo credentials for admin UIs should redact the
 * `apiKey` field to `api_key_present: true`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.0.0
 */
final class Credentials
{
    /**
     * Build the value object.
     *
     * @since 1.0.0
     *
     * @param  string       $provider      Provider slug (e.g. `anthropic`, `openai`).
     * @param  string       $apiKey        Provider API key.
     * @param  string|null  $defaultModel  Optional default model override.
     * @param  string|null  $baseUrl       Optional base URL override.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $apiKey,
        public readonly ?string $defaultModel = null,
        public readonly ?string $baseUrl = null,
    ) {
    }

    /**
     * Prevent accidental exposure of the API key when the object is cast to
     * a string or dumped.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            'Credentials{provider=%s, api_key=REDACTED, default_model=%s, base_url=%s}',
            $this->provider,
            $this->defaultModel ?? 'null',
            $this->baseUrl ?? 'null',
        );
    }

    /**
     * Suppress the API key when the object is inspected via `var_dump`,
     * `print_r`, or JSON encoding.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|string|null>
     */
    public function __debugInfo(): array
    {
        return $this->toPublicArray();
    }

    /**
     * Redacted array representation safe for admin UI serialisation.
     *
     * @since 1.0.0
     *
     * @return array{ provider: string, api_key_present: bool, default_model: string|null, base_url: string|null }
     */
    public function toPublicArray(): array
    {
        return [
            'provider'        => $this->provider,
            'api_key_present' => '' !== $this->apiKey,
            'default_model'   => $this->defaultModel,
            'base_url'        => $this->baseUrl,
        ];
    }
}
