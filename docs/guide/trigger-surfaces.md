---
title: Trigger Surfaces
---

# Building AI trigger surfaces

A *trigger surface* is the controller endpoint or Livewire component that runs an agent on a user's behalf — "Suggest a meta description", "Summarise these responses". Every one of them needs the same three things: a feature-toggle check, a loading state, and a consistent mapping from the five AI failure modes onto something the client can act on. The package ships that plumbing so consumers do not copy it.

## The exception ladder

`ArtisanPackAgent::run()` can throw five kinds of exception. The shared mapping is:

| Exception | HTTP status | `errorCode` | `statusSlug` | Meaning |
|---|---|---|---|---|
| `FeatureDisabledException` | 403 | `feature_disabled` | `disabled` | Admin switched the feature off |
| `MissingCredentialsException` | 503 | `missing_credentials` | `missing-credentials` | No provider key configured |
| `FeatureError` | 422 | `invalid_input` | `invalid-input` | Agent rejected the input, provider call failed, or the model returned malformed JSON |
| `BudgetExceededException` | 429 | `budget_exceeded` | `budget-exceeded` | Monthly hard cap reached (`enforce_hard_cap` on) |
| any other `Throwable` | 500 | `internal_error` | `error` | Logged; the raw message never reaches the client |

## HTTP controllers: `HandlesAiFeatureResponses`

`ArtisanPackUI\Ai\Concerns\HandlesAiFeatureResponses` is a trait (so it composes onto any base controller) that runs an agent callable behind the ladder and returns an immutable `ArtisanPackUI\Ai\Support\AiFeatureOutcome`.

```php
use ArtisanPackUI\Ai\Agents\AltTextGenerationAgent;
use ArtisanPackUI\Ai\Concerns\HandlesAiFeatureResponses;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    use HandlesAiFeatureResponses;

    public function altText( AltTextRequest $request ): JsonResponse
    {
        $outcome = $this->handleAiFeature(
            'ai.alt_text',
            fn () => AltTextGenerationAgent::for( $request->validated()['image'] )->run(),
        );

        if ( $outcome->succeeded ) {
            return new JsonResponse( [ 'feature' => $outcome->feature, 'output' => $outcome->output ] );
        }

        return new JsonResponse( [
            'feature' => $outcome->feature,
            'error'   => $outcome->errorCode,
            'message' => $outcome->message,
        ], $outcome->status );
    }
}
```

Pass the agent as a **callable**, not a built instance: hosts may bind a subclass over the agent, so `for()` is itself a throw site that must run inside the try.

`AiFeatureOutcome` exposes `succeeded`, `feature`, `status`, `errorCode`, `statusSlug`, `message`, and `output` as public readonly properties. The envelope shape stays with the caller — the object only owns the mapping.

Override `aiFeatureLogMessage()` to tag the 500-branch log line with your package (`'cms-framework AI API call failed'`); the logged context shape `[ 'feature' => ..., 'error' => ... ]` is fixed.

`aiFeatureStateMap( iterable $featureKeys )` returns `[ key => bool ]` where a key is `true` only when it is registered **and** toggled on — the map fails closed for keys a host never registered. Use it to tell a JavaScript client which buttons to render.

## Livewire components

Two concerns under `ArtisanPackUI\Ai\Livewire\Concerns` cover the component side.

### `ChecksFeatureToggle`

Declares a computed `$this->isEnabled` that reflects the toggle state of the component's `$featureKey`. The component declares the key; the trait supplies the check.

```php
use ArtisanPackUI\Ai\Livewire\Concerns\ChecksFeatureToggle;
use Livewire\Component;

class InsightSummary extends Component
{
    use ChecksFeatureToggle;

    protected string $featureKey = 'analytics.insight_summary';
}
```

```blade
@if ( $this->isEnabled )
    <x-artisanpack-button wire:click="summarize">{{ __( 'Summarise' ) }}</x-artisanpack-button>
@endif
```

The check fails closed: an unregistered key reports disabled.

### `InteractsWithAiFeature`

Owns the shared `public bool $isLoading` and `public ?string $error` properties and wraps an agent call in `runAiFeature( callable )`. The closure assigns output onto the component; the trait handles loading state and the exception ladder, writing a user-facing message to `$this->error` on failure.

```php
use ArtisanPackUI\Ai\Livewire\Concerns\ChecksFeatureToggle;
use ArtisanPackUI\Ai\Livewire\Concerns\InteractsWithAiFeature;
use Livewire\Component;

class InsightSummary extends Component
{
    use ChecksFeatureToggle;
    use InteractsWithAiFeature;

    protected string $featureKey = 'analytics.insight_summary';

    public ?string $summary = null;

    public function summarize(): void
    {
        $this->summary = null;

        $this->runAiFeature( function (): void {
            $output        = InsightSummaryAgent::for( $this->input() )->run();
            $this->summary = (string) ( $output['summary'] ?? '' );
        } );
    }
}
```

Reset component-specific output fields yourself before calling `runAiFeature()`; the trait only resets `$error`. `FeatureError` messages are passed through verbatim (they are written for end users); every other branch uses a translated generic string.

## Related

- [[testing]] — put `Ai::fake()` behind these surfaces to test them without a provider.
- [[authoring-agents]] — the agent side of the contract.
