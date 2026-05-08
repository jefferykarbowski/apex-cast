# Apex Cast for WooCommerce

> Generate AI social posts from any WooCommerce product, review them inline, and broadcast to Postiz, Buffer, or your own scheduler — without leaving your product editor.

**Status:** v0.1 spec, pre-implementation. Active development.
**Owner:** [Apex Chute LLC](https://apexchute.com)
**License:** GPL-2.0-or-later

---

## What it is

A WordPress plugin that adds a metabox to the WooCommerce product editor. From inside that metabox, a store owner can:

1. **Generate** AI-drafted social media copy for the product, tailored per platform
2. **Review** and edit drafts inline with character counts and per-platform context
3. **Broadcast** approved drafts to a connected social scheduling backend in one click

At v0.1 the plugin ships with an Anthropic AI provider and a Postiz backend adapter. The architecture is designed for additional providers (OpenAI, Gemini) and additional backends (Buffer, Publer) without core refactoring.

---

## Architecture

Two key abstractions, both in place from v0.1:

- **`AIProviderInterface`** — anything that generates platform-tailored drafts from product data. Anthropic implementation ships first.
- **`BackendAdapterInterface`** — anything that authenticates, queues posts to multiple platforms, and reports status. Postiz adapter ships first.

This means adding OpenAI or Buffer in later versions is a single new class, not a refactor.

See [`docs/SPEC-v0.1.md`](docs/SPEC-v0.1.md) for the full v0.1 specification.

---

## Roadmap

| Version | Highlights |
|---------|------------|
| **v0.1** (in progress) | Postiz adapter, Anthropic provider, 6 platforms, product metabox, settings page |
| v0.2 | TikTok inbox mode, Reddit panel with subreddit rules, rich text drafts |
| v0.3 | Buffer + Publer adapters |
| v0.4 | Multisite admin, white-label option |
| v0.5 | Video pipeline integration (Creatomate, Kling) |
| v1.0 | Apex-managed Pro tier (keyless AI via Apex Chute proxy) |

---

## Development

Requirements: PHP 8.1+, WordPress 6.0+, WooCommerce 7.0+, Node 20+, Composer 2+.

```bash
git clone https://github.com/jefferykarbowski/apex-cast.git
cd apex-cast
composer install
npm install
npm run start
```

Local WordPress environment via [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npx wp-env start
# Visit http://localhost:8888
```

---

## Contributing

Contributions welcome. Please open an issue before starting significant work so we can align on direction.

Coding standards:

- PHP: WordPress Coding Standards + WooCommerce ruleset (PHPCS enforced in CI)
- JS: `@wordpress/eslint-plugin` defaults
- Static analysis: PHPStan level 6

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Built by [Apex Chute LLC](https://apexchute.com).
