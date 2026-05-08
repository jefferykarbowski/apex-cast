# Apex Cast — v0.1 Specification

**Project:** Apex Cast for WooCommerce
**Owner:** Apex Chute LLC
**Status:** v0.1 spec, pre-implementation
**Last updated:** May 8, 2026

---

## 1. Overview

Apex Cast adds a metabox to the WooCommerce product editor that generates AI-drafted social media copy for the product, lets the store owner review and edit drafts inline, and publishes them to a connected social scheduling backend (Postiz at v0.1, with adapter abstraction for Buffer, Publer, etc. in later versions).

**Value proposition (one-liner):**

> Generate AI social posts from any WooCommerce product, review them inline, and broadcast to Postiz, Buffer, or your own scheduler — without leaving your product editor.

**Architectural decisions locked in for v0.1:**

1. **Backend abstraction from day one.** Ships with a Postiz adapter only, but the `BackendAdapterInterface` is in place so v0.3 can add Buffer/Publer without refactoring core.
2. **AI provider abstraction from day one.** Ships with an Anthropic provider only, but the `AIProviderInterface` is in place so OpenAI/Gemini providers can plug in cleanly.
3. **BYOK (bring-your-own-key) at v0.1.** User provides their own Anthropic + Postiz API keys. Apex-managed keyless tier is a v1.0 monetization layer.

---

## 2. Plugin Metadata

| Field | Value |
|-------|-------|
| Plugin Name | Apex Cast for WooCommerce |
| Slug | `apex-cast` |
| Text Domain | `apex-cast` |
| Version | 0.1.0 |
| Author | Apex Chute LLC |
| License | GPL-2.0-or-later |
| Requires WP | 6.0 |
| Tested up to | 6.6 |
| Requires PHP | 8.1 |
| Requires WC | 7.0 |
| HPOS Compatible | Yes (declared via `FeaturesUtil::declare_compatibility`) |
| Network | true (multisite-compatible from v0.1, full multisite admin in v0.4) |

---

## 3. Architecture

### 3.1 High-level flow

WooCommerce Product Editor → Apex Cast Metabox (React) → AJAX via REST endpoints → AIProvider (Anthropic) returns drafts → User edits drafts → BackendAdapter (Postiz) queues post → Postiz handles social distribution to FB, IG, Pinterest, Threads, Bluesky, X, etc. → Status reported back via webhook or polling.

### 3.2 Two key abstractions

**`AIProviderInterface`** — anything that can generate platform-tailored social copy from product data. Anthropic is the only implementation at v0.1.

**`BackendAdapterInterface`** — anything that can authenticate, queue posts to multiple platforms, and report status. Postiz is the only implementation at v0.1.

Both abstractions live behind a simple factory in `class-plugin.php` that reads the active selection from settings and instantiates the right class.

---

## 4. Data Model

### 4.1 Custom tables

#### `{prefix}apex_cast_jobs`

One row per "send" event. A single send may target multiple platforms; each platform's status is tracked in the JSON `platform_results` field.

Schema:

- `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
- `product_id` BIGINT UNSIGNED NOT NULL — WooCommerce product
- `user_id` BIGINT UNSIGNED NOT NULL — who triggered the send
- `backend_id` VARCHAR(64) NOT NULL — "postiz", "buffer", etc.
- `backend_post_id` VARCHAR(255) — group ID returned by backend
- `status` VARCHAR(32) NOT NULL — queued | sent | partial | failed
- `platforms` JSON NOT NULL — `["facebook","instagram",...]`
- `platform_results` JSON — `{"facebook":{"status":"sent","url":"..."}, ...}`
- `drafts_snapshot` JSON NOT NULL — the actual content sent
- `created_at` DATETIME NOT NULL
- `updated_at` DATETIME NOT NULL

Indexes on `product_id`, `status`, `created_at`.

#### `{prefix}apex_cast_logs`

Free-form log lines for debugging. Auto-trimmed to 90 days via daily cron.

Schema:

- `id` BIGINT AUTO_INCREMENT PRIMARY KEY
- `job_id` BIGINT — optional linkage to a job row
- `level` VARCHAR(16) NOT NULL — debug | info | warn | error
- `component` VARCHAR(64) NOT NULL — "ai.anthropic", "backend.postiz", "rest", etc.
- `message` TEXT NOT NULL
- `context` JSON
- `created_at` DATETIME NOT NULL

Indexes on `job_id`, `created_at`, `level`.

### 4.2 Options

Single row in `wp_options` with key `apex_cast_settings`. JSON-encoded, sensitive fields encrypted via libsodium:

- `version` — schema version for migrations
- `store.name` — used in AI prompts
- `store.description` — used in AI prompts
- `store.default_platforms` — array of platform IDs enabled by default per product
- `brand_voice.tone` — free text
- `brand_voice.voice_notes` — free text, fed verbatim into AI system prompt
- `brand_voice.hashtag_strategy` — "sparse" | "moderate" | "heavy"
- `brand_voice.do_not_use` — array of phrases/patterns to avoid
- `ai_provider.active` — "anthropic" (v0.1)
- `ai_provider.anthropic.api_key_encrypted` — sodium-encrypted
- `ai_provider.anthropic.model` — defaults to `claude-sonnet-4-6`
- `ai_provider.anthropic.max_tokens`
- `backend.active` — "postiz" (v0.1)
- `backend.postiz.api_key_encrypted`
- `backend.postiz.api_url` — defaults to `https://api.postiz.com/public/v1`
- `backend.postiz.default_post_type` — "now" | "schedule" | "draft"
- `backend.postiz.integration_map` — `{ "facebook": "<postiz-integration-id>", ... }`

### 4.3 Post meta (on WooCommerce products)

| Meta key | Purpose |
|----------|---------|
| `_apex_cast_drafts` | JSON blob of latest generated drafts. Survives page reload. |
| `_apex_cast_last_sent_at` | Unix timestamp of last successful send. |
| `_apex_cast_last_job_id` | Pointer to last job row for "view results" link. |

---

## 5. AI Provider System

### 5.1 Interface

The interface (in `includes/ai/interface-ai-provider.php`) defines four methods: `generate_drafts(ProductContext, array, BrandVoice): GenerationResult`, `get_supported_platforms(): array`, `get_provider_id(): string`, `test_connection(): TestConnectionResult`.

`ProductContext`, `BrandVoice`, `GenerationResult`, `TestConnectionResult` are simple value objects defined alongside the interface.

### 5.2 Anthropic provider implementation

Calls `https://api.anthropic.com/v1/messages` with model `claude-sonnet-4-6` (configurable).

**System prompt template:**

```
You are a social media copywriter for {store.name}. {store.description}

Brand voice:
- Tone: {brand_voice.tone}
- Voice notes: {brand_voice.voice_notes}
- Hashtag strategy: {brand_voice.hashtag_strategy} (sparse | moderate | heavy)
- Avoid: {brand_voice.do_not_use}

You write platform-specific copy that respects each platform's culture and constraints. You output ONLY valid JSON matching the requested schema. No prose, no markdown fences, no preamble.

Platform conventions:
- facebook: 1-3 sentences, conversational, can include link. Max 500 chars. Light hashtag use.
- instagram: 2200 char max. Strong opener. Hashtags grouped at the end (per hashtag_strategy).
- pinterest: Keyword-rich descriptive caption. Max 500 chars. Search-optimized phrasing.
- threads: 500 char max. Casual, conversational, lowercase opener common.
- bluesky: 300 char max. No hashtag culture — use only if essential. Direct, link-friendly.
- x: 280 char max. Punchy. 1-2 hashtags max.
- tiktok: Caption only (200 chars max for hook). User adds video manually.
- reddit: Title + body. Title 300 chars. Body markdown-friendly. NEVER promotional-sounding.
```

**User message template:**

```
Generate social copy for this product across these platforms: {platforms}

Product:
- Title: {product.title}
- Permalink: {product.permalink}
- Short description: {product.short_description}
- Full description (truncated): {product.description_excerpt}
- Price: {product.price_formatted}
- Categories: {product.categories}
- Tags: {product.tags}
- Stock status: {product.stock_status}
- Featured image URL: {product.featured_image}

Output schema (use exactly these keys, one entry per requested platform):
{
  "drafts": {
    "<platform>": {
      "content": "<the post copy>",
      "hashtags": ["<#tag1>", "<#tag2>"],
      "char_count": <int>
    }
  },
  "notes": "<one short sentence about your creative angle for this product, for the human reviewer>"
}

Do NOT include placeholder text. Do NOT include the URL twice. Do NOT use exclamation marks unless brand_voice.voice_notes explicitly requests them.
```

**Response parsing:** Strip any code fences if present (defensive), parse JSON, validate required keys per platform, return `GenerationResult` with drafts plus the model's "notes" field (shown to the user as an italic helper line: "Creative angle: …").

**Error handling:**

- HTTP 401 → `AIProviderException::auth_failed()` → user-facing "API key invalid"
- HTTP 429 → exponential backoff retry up to 3 times
- HTTP 5xx → retry once, then fail
- Invalid JSON → log raw response, generic "model returned malformed output, please try again"
- Token usage logged to `apex_cast_logs` for cost tracking

---

## 6. Backend Adapter System

### 6.1 Interface

The interface (in `includes/adapters/interface-backend-adapter.php`) defines: `test_connection()`, `fetch_integrations(): array`, `upload_media(string, string): MediaRef`, `queue_post(PostPayload): QueueResult`, `get_post_status(string): PostStatus`, `get_adapter_id(): string`.

### 6.2 Postiz adapter implementation

**Auth:** API key in `Authorization` header, no Bearer prefix. User obtains key from Postiz Settings page.

**Base URL:** Configurable. Default `https://api.postiz.com/public/v1` for cloud. User can override for self-hosted.

**Rate limit awareness:** Postiz allows 30 requests/hour per API key. The adapter MUST batch all platforms for a single send into one `POST /posts` call (Postiz natively supports multi-platform in one request). Track requests in a transient with hourly reset.

**Postiz call sequence for a send:**

1. **Map our platform IDs to Postiz integration IDs.** User maps once in settings (UI: "For Facebook posts, use Postiz integration: [dropdown of connected FB pages]"). Persisted as `backend.postiz.integration_map`.
2. **(If post has media) `POST /upload`** — multipart/form-data. Returns `{ id, path }`.
3. **`POST /posts`** with payload structured as:
   - `type`: "now" | "schedule" | "draft"
   - `date`: ISO-8601 timestamp
   - `shortLink`: false
   - `tags`: []
   - `posts`: array of per-platform objects, each with `integration.id`, `value` (array with content + image refs), `settings.__type` (the platform identifier)
4. **Capture response.** Postiz returns a group ID and per-post IDs. Store group ID as `backend_post_id` on the job row.
5. **Status polling** (v0.1: after 60 seconds, GET each post ID to check publish state. Recorded in `platform_results` JSON.)

**Webhook (v0.2):** Postiz can send webhooks on status changes. Plugin exposes `/wp-json/apex-cast/v1/webhook/postiz` for this. v0.1 does not require it; status checked on next page load via direct GET.

**Settings UI for Postiz:**

- API key (encrypted storage)
- API URL (default postiz.com cloud, override for self-hosted)
- "Test connection" button → calls `test_connection()`, hits `GET /integrations`, reports success + integration list
- Integration mapping table: for each Apex Cast platform, dropdown of user's matching Postiz integrations
- Default post type: now | schedule | draft

---

## 7. REST Endpoints

All endpoints under `/wp-json/apex-cast/v1/`. All require `manage_woocommerce` capability and a valid nonce.

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/generate` | Body: `{product_id, platforms[]}`. Calls active AI provider. Returns drafts JSON. |
| `POST` | `/save-drafts` | Body: `{product_id, drafts}`. Persists to `_apex_cast_drafts` post meta. |
| `POST` | `/send` | Body: `{product_id, drafts, platforms[], post_type, scheduled_at?}`. Creates job, calls backend, returns `{job_id, status}`. |
| `GET`  | `/jobs/:id` | Returns job row + latest platform_results. |
| `GET`  | `/jobs?product_id=X` | Recent jobs for a product (history view). |
| `POST` | `/test-connection` | Body: `{provider_type: "ai"\|"backend"}`. Returns `{success, message, details?}`. |
| `GET`  | `/integrations` | Proxies backend's fetch_integrations() for settings UI. |
| `POST` | `/webhook/:adapter_id` | Public, signature-validated. For backend status callbacks. |

**Response envelope:** All endpoints return `{ ok: bool, data?: any, error?: { code, message } }`.

---

## 8. UI

### 8.1 Settings page (`Settings → Apex Cast`)

Tab structure:

1. **General** — store name, description, default platforms
2. **Brand Voice** — tone, voice notes textarea, hashtag strategy radio, do-not-use list
3. **AI Provider** — active provider dropdown, API key, model selector, "Test connection" button
4. **Backend** — active backend dropdown, API key, API URL, "Test connection", platform → integration mapping table
5. **Logs** — paginated log table (level filter, component filter, last 90 days)

Built with classic WP Settings API + small React islands for test-connection buttons and integration mapping table.

### 8.2 Product editor metabox

Position: side column, below Product Categories.

Layout components:

- Header with "⚙ Settings" link to settings page
- Primary "✨ Generate Social Drafts" button (full width, prominent)
- Platform checkbox grid (8 platforms, 2 columns)
- After generation: "💡 Creative angle: …" helper line (italic, derived from AI's "notes" field)
- Per-platform expandable cards with editable textarea, character count display, hashtag chips
- Bottom action area:
  - Post type radio: Now | Schedule | Draft
  - "🚀 Send to Postiz (N platforms)" button
  - "Last sent" status line with link to job log

**States:** Empty → Generating → Drafted → Sending → Sent | Partial failure | Error.

### 8.3 React structure

In `assets/src/metabox/`:

- `index.jsx` — mount point, reads `window.APEX_CAST_DATA`
- `ApexCastMetabox.jsx` — main component, state machine via `useReducer`
- `PlatformCard.jsx` — single platform draft editor
- `PlatformPicker.jsx` — checkbox grid
- `SendBar.jsx` — bottom action area
- `api.js` — wrapper around fetch() + nonce + REST URLs
- `styles.scss`

State management: plain `useReducer`. No Redux.

---

## 9. Job Lifecycle

User clicks Generate → POST /generate → AIProvider.generate_drafts() → api.anthropic.com → drafts returned to React (not yet persisted) → optional edit → optional save-drafts persistence → user clicks Send → POST /send → create job row (status: queued) → BackendAdapter.queue_post() → api.postiz.com/posts → update job row (status: sent), store backend_post_id → success returned to React → toast, "last sent" updated, drafts cleared.

---

## 10. Security

- **API key encryption.** `wp_options` sensitive fields encrypted via `sodium_crypto_secretbox` with key derived from `AUTH_KEY` + plugin salt. OpenSSL fallback if libsodium unavailable.
- **Capability checks.** Every REST endpoint verifies `current_user_can('manage_woocommerce')`. Webhook endpoint validates HMAC signature instead.
- **Nonces.** All state-changing AJAX calls include `wp_rest` nonce.
- **Input sanitization.** Product context built server-side from canonical WC data. User-edited drafts stripped via `wp_kses_post`.
- **Rate limiting (per user).** Generate endpoint limited to 60 calls/hour per user via transient.
- **Webhook signature validation.** `/webhook/:adapter_id` requires HMAC signature header.
- **No outbound calls during plugin activation.**

---

## 11. Build, Test, Deploy

**Local dev:** `wp-env` (preferred) or LocalWP.

**Build:**

- `composer install` — PHP deps (Guzzle for HTTP, libsodium polyfill)
- `npm install` — JS deps
- `npm run build` — `@wordpress/scripts build` produces `assets/build/`
- `npm run start` — watch mode

**Lint:**

- PHP: `composer run lint` (PHPCS + WP coding standards + WC ruleset)
- JS: `npm run lint` (`@wordpress/eslint-plugin`)
- Static analysis: `composer run analyze` (PHPStan level 6)

**Test:**

- Unit (PHP): `composer run test` (PHPUnit)
- Unit (JS): `npm test` (Jest)
- E2E: `npm run test:e2e` (Playwright against wp-env)

**CI:** `.github/workflows/ci.yml` runs lint + unit + E2E on every PR.

**Release:**

- Tag-triggered `release.yml`:
  - Builds production assets
  - Creates ZIP excluding `node_modules/`, `tests/`, `.github/`
  - Attaches to GitHub Release
  - On `v*.*.0` tags: pushes to WP.org SVN (requires `WPORG_USERNAME` + `WPORG_PASSWORD` secrets)

---

## 12. Roadmap

| Version | Scope |
|---------|-------|
| v0.2 | TikTok inbox-mode handling, Reddit panel with subreddit-aware rule reminders, rich text drafts |
| v0.3 | Buffer adapter, Publer adapter, adapter-comparison docs |
| v0.4 | Multisite admin support, white-label option |
| v0.5 | Video pipeline integration (Creatomate/Kling adapters as separate "media providers" with same abstraction pattern) |
| v1.0 | Apex-managed Pro tier — keyless AI via Apex Chute proxy, premium analytics dashboard, paid support |

---

## 13. Open Questions

- Encryption key rotation strategy when `AUTH_KEY` changes — graceful "re-enter your API keys" flow.
- Postiz draft mode UX — confirm `type: "draft"` is right for our "save to scheduler as draft" UX.
- Anthropic prompt caching — system prompt is identical across calls; should use prompt caching to reduce token costs.
- Image handling for non-product images — defer "select different image from gallery" to v0.2.
- Multi-language store support (WPML/Polylang detection) — defer to v0.4.
