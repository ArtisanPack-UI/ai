# React and Vue integration

The `artisanpack-ui/ai` JSON API is designed to be consumed by any JavaScript client. Both `@artisanpack-ui/react` and `@artisanpack-ui/vue` ship pre-built components — `SettingsPage`, `UsageDashboard`, and `FeatureToggles` — that call these endpoints directly.

This document covers the wiring that lives outside the components themselves: authentication, base URLs, and streaming.

## Package versions

| Framework | Package                    | Subpath                     |
|-----------|----------------------------|-----------------------------|
| React     | `@artisanpack-ui/react`    | `@artisanpack-ui/react/ai`  |
| Vue       | `@artisanpack-ui/vue`      | `@artisanpack-ui/vue/ai`    |

Both subpaths export the same surface:

- `SettingsPage` — form backed by `GET /settings`, `PUT /settings`, and `POST /test-connection`
- `UsageDashboard` — dashboard backed by `GET /usage` with an optional `refreshInterval` for polling
- `FeatureToggles` — per-feature switch list backed by `GET /features` + `POST /features/{key}/toggle`
- `createAiApiClient` — small `fetch` wrapper you pass to each component
- `useStreamingText` — hook / composable for long-running agent-output streams
- `AiApiError` — typed error thrown for non-2xx responses (exposes `.status` and `.body`)

## The API client

Every component takes a `client` prop implementing the `AiApiClient` contract. In most apps you'll build one with `createAiApiClient`:

```ts
import { createAiApiClient } from '@artisanpack-ui/react/ai';
// or '@artisanpack-ui/vue/ai'

const client = createAiApiClient({
  baseUrl: '/api/artisanpack-ai',
  headers: {
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
  },
});
```

### Options

| Option        | Type                          | Notes                                                          |
|---------------|-------------------------------|----------------------------------------------------------------|
| `baseUrl`     | `string`                      | Prefix for every request. Match the ai package's route prefix. |
| `headers`     | `Record<string, string>`      | Merged into every request (CSRF, Sanctum bearer, etc).         |
| `fetchImpl`   | `typeof fetch`                | Override the global `fetch` (tests, custom auth wrappers).     |

### Custom clients

If your app has its own HTTP layer — Axios, Ky, or a Sanctum-aware fetch wrapper — implement the `AiApiClient` interface directly rather than using `createAiApiClient`. Every component only depends on the interface, not on `fetch`.

## Authentication

All ai endpoints are Sanctum-authenticated by default and gated on the `manage_ai_settings` ability. When calling from a same-origin SPA that shares a session cookie with the Laravel app, the default `fetch` credentials of `same-origin` plus a CSRF header is enough.

For token-based auth, add the bearer to `createAiApiClient` headers:

```ts
const client = createAiApiClient({
  baseUrl: '/api/artisanpack-ai',
  headers: { Authorization: `Bearer ${token}` },
});
```

## Handling validation errors

The ai API returns `422` with a `{ message, errors }` envelope for validation failures (e.g. missing API key, invalid provider switch). `SettingsPage` reads this envelope from `AiApiError.body` automatically and renders field-level messages. If you build your own form, catch the error and inspect `body.errors`:

```ts
import { AiApiError } from '@artisanpack-ui/react/ai';

try {
  await client.updateSettings(payload);
} catch (err) {
  if (err instanceof AiApiError && err.status === 422) {
    const { errors } = err.body as { errors: Record<string, string[]> };
    // render errors per field
  }
}
```

## Live usage dashboard

Pass `refreshInterval` (in milliseconds) to `UsageDashboard` to enable polling for live updates:

```tsx
<UsageDashboard client={client} refreshInterval={15_000} />
```

The `/usage` endpoint is cheap enough for a 15-second cadence (it aggregates from `ai_usage_events`), which matches the Livewire dashboard's default refresh rate.

## Streaming long-running agent output

For long-running agents (multi-second generations, chain-of-thought output, etc.) the ai package can stream chunks over a plain `fetch` response body. Both packages ship a `useStreamingText` hook/composable that consumes that stream:

```tsx
// React
import { useStreamingText } from '@artisanpack-ui/react/ai';

function AgentOutput({ agentUrl }: { agentUrl: string }) {
  const { text, streaming, error, start, stop } = useStreamingText();

  return (
    <div>
      <button onClick={() => start(agentUrl)} disabled={streaming}>Run</button>
      <button onClick={stop} disabled={!streaming}>Stop</button>
      <pre>{text}{streaming && '…'}</pre>
      {error && <span role="alert">{error.message}</span>}
    </div>
  );
}
```

```vue
<!-- Vue -->
<script setup lang="ts">
import { useStreamingText } from '@artisanpack-ui/vue/ai';

const { text, streaming, error, start, stop } = useStreamingText();
</script>

<template>
  <button :disabled="streaming" @click="start('/api/artisanpack-ai/agents/run')">Run</button>
  <button :disabled="!streaming" @click="stop">Stop</button>
  <pre>{{ text }}<span v-if="streaming">…</span></pre>
  <span v-if="error" role="alert">{{ error.message }}</span>
</template>
```

The hook uses the browser Streams API + an `AbortController`, and aborts the in-flight stream automatically when the component unmounts (React) or the scope disposes (Vue).

## Type-level parity with the API schema

Both packages export TypeScript types that mirror the [OpenAPI schema](./api-schema.json):

- `AiSettingsResponse`, `AiSettingsUpdate`, `AiCredentials`, `AiFeatureOverride`
- `AiFeature`
- `AiUsageResponse`, `AiUsageTotals`, `AiUsageByFeature`, `AiUsageDaily`
- `AiConnectionTestResult`
- `AiValidationError`

If you write a custom client, import these types directly and lean on them for compile-time coverage.
