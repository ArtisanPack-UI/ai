<?php

/**
 * Ai test-double.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ai\Testing;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * Deterministic AI test-double, installed via {@see \ArtisanPackUI\Ai\Ai::fake()}.
 *
 * While a fake is active every {@see ArtisanPackAgent::run()} short-circuits
 * into {@see handle()} instead of resolving credentials or calling a provider:
 * the run is recorded and a queued response is returned. Tests queue responses
 * per agent class and assert on which agent ran, how many times, and with what
 * input.
 *
 * ```php
 * $fake = Ai::fake( [ SummarizationAgent::class => [ 'summary' => 'ok', 'key_points' => [], 'caveats' => [] ] ] );
 *
 * SummarizationAgent::for( [ 'items' => [ 'a' ] ] )->run();
 *
 * $fake->assertRan( SummarizationAgent::class );
 * $fake->assertRanTimes( SummarizationAgent::class, 1 );
 * ```
 *
 * @package    ArtisanPack_UI
 * @subpackage Ai
 *
 * @since      1.2.0
 */
class AiFake
{
    /**
     * FIFO response queues, keyed by agent class. A queued response is
     * consumed before the class default and takes precedence.
     *
     * @since 1.2.0
     *
     * @var array<class-string, array<int, array<string, mixed>>>
     */
    protected array $responses = [];

    /**
     * Default response per agent class, returned once the class queue is
     * drained (or when no queue was set).
     *
     * @since 1.2.0
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected array $defaults = [];

    /**
     * Recorded runs, in the order they happened.
     *
     * @since 1.2.0
     *
     * @var array<int, array{ agent: class-string, feature: string, input: mixed }>
     */
    protected array $runs = [];

    /**
     * Seed the fake with a map of `agentClass => default response output`.
     *
     * Each value is the `output` array the agent's `run()` would normally
     * return (the shape declared by its `outputSchema()`), not the
     * `{ output, input_tokens, output_tokens }` prompter envelope.
     *
     * @since 1.2.0
     *
     * @param  array<class-string, array<string, mixed>>  $responses  Map of agent class to default output.
     */
    public function __construct( array $responses = [] )
    {
        foreach ( $responses as $agentClass => $output ) {
            $this->defaults[ $agentClass ] = $output;
        }
    }

    /**
     * Push a response onto the FIFO queue for an agent class.
     *
     * Queued responses are returned in order, one per run, before the class
     * default. Call this multiple times to script a sequence of runs.
     *
     * @since 1.2.0
     *
     * @param  class-string          $agentClass  Agent class the response is for.
     * @param  array<string, mixed>  $output      Output array to return from `run()`.
     *
     * @return self
     */
    public function queue( string $agentClass, array $output ): self
    {
        $this->responses[ $agentClass ][] = $output;

        return $this;
    }

    /**
     * Set the default response returned for an agent class once its queue is
     * drained.
     *
     * @since 1.2.0
     *
     * @param  class-string          $agentClass  Agent class the response is for.
     * @param  array<string, mixed>  $output      Output array to return from `run()`.
     *
     * @return self
     */
    public function respondWith( string $agentClass, array $output ): self
    {
        $this->defaults[ $agentClass ] = $output;

        return $this;
    }

    /**
     * Record a run and return the deterministic response for the agent.
     *
     * Called by {@see ArtisanPackAgent::run()} while a fake is active.
     * Precedence: queued response → class default → a {@see LogicException}
     * so a test that forgot to script the agent fails loudly instead of
     * silently receiving an empty payload.
     *
     * @since 1.2.0
     *
     * @param  ArtisanPackAgent  $agent  Agent being run.
     * @param  mixed             $input  Domain input the agent was built with.
     *
     * @throws LogicException When no response is queued or defaulted for the agent class.
     *
     * @return array<string, mixed>
     */
    public function handle( ArtisanPackAgent $agent, mixed $input ): array
    {
        $agentClass = $agent::class;

        $this->runs[] = [
            'agent'   => $agentClass,
            'feature' => $agent->featureKey,
            'input'   => $input,
        ];

        if ( ! empty( $this->responses[ $agentClass ] ) ) {
            return array_shift( $this->responses[ $agentClass ] );
        }

        if ( array_key_exists( $agentClass, $this->defaults ) ) {
            return $this->defaults[ $agentClass ];
        }

        throw new LogicException( sprintf(
            'No fake AI response is queued for agent [%1$s]. Queue one with '
            . 'Ai::fake()->queue( %1$s::class, [ ... ] ) or seed a default via '
            . 'Ai::fake( [ %1$s::class => [ ... ] ] ).',
            $agentClass,
        ) );
    }

    /**
     * Recorded runs for an agent class, optionally filtered by a callback
     * that receives the input each run was made with.
     *
     * @since 1.2.0
     *
     * @param  class-string     $agentClass  Agent class to filter on.
     * @param  callable|null    $callback    Optional `fn ( mixed $input ): bool` filter.
     *
     * @return array<int, array{ agent: class-string, feature: string, input: mixed }>
     */
    public function ran( string $agentClass, ?callable $callback = null ): array
    {
        return array_values( array_filter(
            $this->runs,
            static fn ( array $run ): bool => $run['agent'] === $agentClass
                && ( null === $callback || (bool) $callback( $run['input'] ) ),
        ) );
    }

    /**
     * Every recorded run, in order.
     *
     * @since 1.2.0
     *
     * @return array<int, array{ agent: class-string, feature: string, input: mixed }>
     */
    public function runs(): array
    {
        return $this->runs;
    }

    /**
     * Assert an agent ran at least once, optionally matching a callback on
     * the input it was run with.
     *
     * @since 1.2.0
     *
     * @param  class-string   $agentClass  Agent class expected to have run.
     * @param  callable|null  $callback    Optional `fn ( mixed $input ): bool` filter.
     *
     * @return self
     */
    public function assertRan( string $agentClass, ?callable $callback = null ): self
    {
        Assert::assertNotEmpty(
            $this->ran( $agentClass, $callback ),
            sprintf(
                'Expected agent [%s] to have run%s, but it did not.',
                $agentClass,
                null !== $callback ? ' matching the given callback' : '',
            ),
        );

        return $this;
    }

    /**
     * Assert an agent ran an exact number of times.
     *
     * @since 1.2.0
     *
     * @param  class-string  $agentClass  Agent class to count runs for.
     * @param  int           $times       Expected run count.
     *
     * @return self
     */
    public function assertRanTimes( string $agentClass, int $times ): self
    {
        $count = count( $this->ran( $agentClass ) );

        Assert::assertSame(
            $times,
            $count,
            sprintf(
                'Expected agent [%s] to run %d time(s), but it ran %d time(s).',
                $agentClass,
                $times,
                $count,
            ),
        );

        return $this;
    }

    /**
     * Assert an agent never ran, optionally scoped to runs matching a
     * callback on the input.
     *
     * @since 1.2.0
     *
     * @param  class-string   $agentClass  Agent class expected not to have run.
     * @param  callable|null  $callback    Optional `fn ( mixed $input ): bool` filter.
     *
     * @return self
     */
    public function assertNotRan( string $agentClass, ?callable $callback = null ): self
    {
        $count = count( $this->ran( $agentClass, $callback ) );

        Assert::assertSame(
            0,
            $count,
            sprintf(
                'Expected agent [%s] not to have run%s, but it ran %d time(s).',
                $agentClass,
                null !== $callback ? ' matching the given callback' : '',
                $count,
            ),
        );

        return $this;
    }

    /**
     * Assert no agents ran at all while the fake was active.
     *
     * @since 1.2.0
     *
     * @return self
     */
    public function assertNothingRan(): self
    {
        Assert::assertEmpty(
            $this->runs,
            sprintf( 'Expected no agents to run, but %d did.', count( $this->runs ) ),
        );

        return $this;
    }
}
