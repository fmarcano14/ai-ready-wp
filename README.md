[![CI](https://github.com/fmarcano14/ai-ready-wp/actions/workflows/ci.yml/badge.svg?branch=v1)](https://github.com/fmarcano14/ai-ready-wp/actions/workflows/ci.yml)

# AI Ready WP

AI Ready WP is a single-site WordPress plugin. It writes `/llms.txt` for the public site, an optional `/llms-full.txt`, and a Markdown view of each included post or page. Everything is generated on the server. Nothing is sent to an external service.

![Settings screen](assets/screenshot-1.png)

## Install

1. Copy this directory to `wp-content/plugins/ai-ready-wp`.
2. Activate **AI Ready WP** on a single site. It does not run on multisite.
3. Open **Settings > AI Ready** and press **Generate files**.

Pretty permalinks produce `/sample-post.md`. Plain permalinks produce `?format=markdown` on that post. Both responses are `text/markdown; charset=utf-8`.

## Usage

- **Settings > AI Ready** chooses the summary, the optional details, which public post types are included, which of those belong under Optional, whether to write `llms-full.txt`, and the note length.
- On each post or page, **Exclude from AI files** leaves that document out. A noindex flag from Yoast, Rank Math, SEOPress, All in One SEO, or The SEO Framework does the same.
- `wp ai-ready generate` rebuilds the files. `wp ai-ready status` prints whether they exist.
- Saving a public post schedules one rebuild 30 seconds later. Unpublishing, adding a password, excluding a document, or marking it noindex updates the files in that same request.
- Deleting the plugin removes its settings, the exclude flag, the scheduled rebuild, and the generated files.

Apache is given an `.htaccess` rule that denies direct requests to `wp-content/uploads/ai-ready/`. On nginx, deny that directory in the server config. The public URLs are `/llms.txt`, `/llms-full.txt`, and the Markdown views.

## Sample llms.txt

```text
# Café 🌧️

> Resumen ñ

Short details.

## Posts

- [Café niño 🌧️](https://example.com/cafe-nino.md): Hello <div> user-0 visitor
- [River notes](https://example.com/river-notes.md): Manual note about ñ

## Optional

- [Old checklist](https://example.com/old-checklist.md): Archive page.
```

## Design

Inclusion, notes, and the llms.txt builder are pure PHP and do not load WordPress.
The plugin reads posts, writes one directory under uploads, and serves those files.
Visitors never trigger a rebuild: edits wait on one cron event, and unpublishing, a password, or an exclude flag rewrites the files in that request.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
