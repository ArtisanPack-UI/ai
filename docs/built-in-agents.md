# Built-in agents

The `artisanpack-ui/ai` package ships three cross-cutting agents that live here (rather than in any single downstream package) because two or more packages consume them:

| Agent                      | Feature key            | Consumers                                          |
|----------------------------|------------------------|----------------------------------------------------|
| `AltTextGenerationAgent`   | `ai.alt_text`          | `media-library` (on upload), `visual-editor` (on drop) |
| `ContentRewriteAgent`      | `ai.content_rewrite`   | `visual-editor`, `cms-framework`                   |
| `SummarizationAgent`       | `ai.summarize`         | `analytics` digests, `security-analytics` digests  |

All three are registered via `AiServiceProvider::aiFeatures()` and appear in the Settings → Features admin surface out of the box.

---

## `AltTextGenerationAgent`

Vision-capable agent that generates accessibility-friendly alt text for an image.

### Input

Any of:

- a local filesystem path (`/absolute/or/relative/photo.jpg`)
- a fully-qualified `http(s)://` URL
- a raw base64 string, or a `data:image/...` URI
- an explicit `[ 'source' => 'path|url|base64', 'value' => string ]` pair

Bytes are never read off disk here — the reference is forwarded to the model provider as-is (Anthropic, OpenAI, and Gemini fetch/upload it for you; Ollama with `llava` reads local paths directly).

### Output

```json
{
  "alt_text":   "Sunset over the Rocky Mountains",
  "confidence": 0.92,
  "warnings":   ["screenshot of text — provide the transcription in body content"]
}
```

- `alt_text` — capped at 150 characters, no trailing period.
- `confidence` — clamped to `[0.0, 1.0]`.
- `warnings` — advisory notes about the image (screenshot of text, decorative, unclear).

### Usage

```php
use ArtisanPackUI\Ai\Agents\AltTextGenerationAgent;

$result = AltTextGenerationAgent::for( $path )->run();

$post->update([
    'alt_text' => $result['alt_text'],
]);
```

### Errors

Throws `\ArtisanPackUI\Ai\Exceptions\FeatureError` when the input isn't an image reference (unreadable path, unsupported source, empty string, null).

Throws the standard `\ArtisanPackUI\Ai\Exceptions\FeatureDisabledException` when the `ai.alt_text` toggle is off, and `\ArtisanPackUI\Ai\Exceptions\MissingCredentialsException` when no provider key is configured.

### Provider notes

- **Anthropic** — `claude-haiku-4-5` (default). Supports URL + base64.
- **OpenAI** — `gpt-4o-mini`. Supports URL + base64.
- **Gemini** — `gemini-2.0-flash`. Supports URL + base64.
- **Ollama** — `llava` (or any vision-capable local model). Set `default_model` on the ai admin settings page.

---

## `ContentRewriteAgent`

General-purpose content rewriting: "make this shorter", "more formal", "reading level 6".

### Input

```php
[
    'content'     => string,       // required, the text to rewrite
    'intent'      => string,       // required, "shorten" / "more formal" / "reading level 6" / etc.
    'constraints' => string[],     // optional, extra rules for the model
]
```

### Output

```json
{
  "rewrite":       "…",
  "changed_ratio": 0.35,
  "rationale":     "Elevated register to formal business tone."
}
```

- The model is instructed to **preserve markdown / HTML** structure when the input contains it — the rewrite comes back in the same format.
- The model is instructed to **return the input unchanged** when the intent doesn't apply (already short, already at the requested reading level, etc.). Consumers can trust `rewrite === $input['content']` + `changed_ratio === 0.0` as a signal that no false rewrite happened.
- `changed_ratio` is sanity-checked against a `similar_text()` floor — if the model claims `0` but the rewrite actually differs, the agent recomputes rather than trust a hallucinated number.

### Usage

```php
use ArtisanPackUI\Ai\Agents\ContentRewriteAgent;

$result = ContentRewriteAgent::for( [
    'content'     => $post->body,
    'intent'      => 'reading level 8',
    'constraints' => [ 'preserve every heading', 'do not change code blocks' ],
] )->run();

if ( $result['changed_ratio'] > 0.0 ) {
    // …offer the rewrite as a diff to the editor.
}
```

### Errors

Throws `FeatureError` when `content` or `intent` is missing or empty, or when the input isn't an array.

---

## `SummarizationAgent`

Generic summarization agent designed to be **subclassed** by digest features rather than reimplemented per surface.

### Input

```php
[
    'items'  => array,       // required, list of things to summarize
    'focus'  => string,      // optional, focus lens ("user impact", "revenue", etc.)
    'length' => 'brief'|'detailed',  // optional, defaults to 'brief'
]
```

`items` is intentionally loose — the model receives its JSON-encoded form, so callers can pass log lines, associative arrays, Eloquent `Arrayable` objects, or a mix. Subclasses that need to pre-shape items should override `normalizeItems()`.

### Output

```json
{
  "summary":    "…",
  "key_points": ["…", "…"],
  "caveats":    ["sample size was small"]
}
```

- `length: brief` — up to 2 sentences in `summary`, up to 3 `key_points`.
- `length: detailed` — up to 5 sentences in `summary`, up to 7 `key_points`.
- Empty input short-circuits without hitting the model: returns `"No items to summarize."` with `caveats: ["input list was empty"]`.

### Subclassing

Analytics/security-analytics digests should extend rather than reimplement:

```php
namespace ArtisanPackUI\Analytics\Agents;

use ArtisanPackUI\Ai\Agents\SummarizationAgent;

class WeeklyDigestAgent extends SummarizationAgent
{
    public string $featureKey   = 'analytics.weekly_digest';
    public string $package      = 'artisanpack-ui/analytics';

    // Optionally: bias the prompt for this specific digest.
    public function instructions(): string
    {
        return parent::instructions() . "\n\nAlways group findings by pillar (traffic, engagement, revenue).";
    }

    // Optionally: strip PII / bucket events before serialization.
    protected function normalizeItems( array $items ): array
    {
        return array_map( fn ( array $event ): array => [
            'ts'  => $event['created_at']->format( 'Y-m-d' ),
            'msg' => $event['message'],
        ], $items );
    }
}
```

Register the subclass from the analytics package's own `aiFeatures()`:

```php
public function aiFeatures(): array
{
    return [
        'analytics.weekly_digest' => [
            'agent'   => WeeklyDigestAgent::class,
            'package' => 'artisanpack-ui/analytics',
        ],
    ];
}
```

Everything else — feature-toggle gating, credential resolution, cache, telemetry, override precedence — is inherited from the base pipeline.

### Errors

Throws `FeatureError` when the input isn't an array or when `items` is missing.

---

## Extending the prompter

Under the hood every agent's `execute()` delegates to an `AgentPrompter` binding. The default implementation is `LaravelAiAgentPrompter`, which wraps `Laravel\Ai\StructuredAnonymousAgent` — you get provider failover, broadcast/queue dispatch, and `Ai::fake()` support out of the box.

If you need to route through a different provider stack entirely (custom HTTP client, offline stub, air-gapped deployment), rebind the contract in your own service provider:

```php
$this->app->singleton(
    \ArtisanPackUI\Ai\Contracts\AgentPrompter::class,
    MyCustomPrompter::class,
);
```

Every shipped agent uses this seam, so a single binding replaces the model transport for all of them.
