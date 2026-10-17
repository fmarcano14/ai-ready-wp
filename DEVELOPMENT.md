# Development

AI Ready WP is a single-site WordPress plugin with no runtime Composer dependencies. `composer install` pulls PHPCS, PHPCompatibility, and PHPUnit only. Production loads `vendor/autoload.php` when it exists and otherwise uses `Fer\AiReady\Autoloader`. The `Fer\AiReady` namespace is the collision boundary, so there is no prefixed third-party tree to ship.

## Layout

| Path | Responsibility |
| --- | --- |
| `ai-ready-wp.php` | Headers, PHP and WordPress version gate, autoload, activation hooks. |
| `src/Plugin.php` | Composition root. Every WordPress hook is registered here. |
| `src/Settings.php` | Option `ai_ready_wp_settings` and the WordPress settings API. |
| `src/SettingsSanitizer.php` | Pure sanitizer. Unit tests run it without WordPress. |
| `src/Publication.php` | Eligibility: status, password, post type, exclusions, noindex, discovery mode. |
| `src/PostTypes.php` | Public post types offered in the settings table. |
| `src/Exclusions.php` | ID, slug, and path rules from the textarea. |
| `src/Robots.php` | Reads known SEO noindex meta shapes. |
| `src/UrlPolicy.php` | Allowlist for links and images emitted in Markdown. |
| `src/Sanitizer.php` | Second boundary on generated Markdown: UTF-8, size, fences, unsafe URLs. |
| `src/MarkdownUrl.php` | Permalink to `.md` or `?format=markdown`. |
| `src/Content/Renderer.php` | Runs `the_content` in process, then converts. |
| `src/Content/MarkdownConverter.php` | DOM conversion from rendered HTML to Markdown. |
| `src/Content/LlmsTxtBuilder.php` | Spec-shaped `/llms.txt`. |
| `src/Content/LlmsFullBuilder.php` | Concatenated `/llms-full.txt`. |
| `src/Catalog.php` | Queries eligible documents and groups sections. |
| `src/Generator.php` | Builds, sanitizes, and stores the indexes. |
| `src/Cache/Store.php` | Transients, upload files, fingerprints, and locks. |
| `src/Cron/Regenerator.php` | Debounced `ai_ready_wp_regenerate` event. |
| `src/Http/Router.php` | Rewrites, query vars, canonical guard, response headers. |
| `src/Http/Discovery.php` | `wp_head` links and the admin-bar node. |
| `src/Admin/SettingsPage.php` | Settings screen, Generate now, preview AJAX. |
| `src/Admin/MetaBox.php` | Per-document exclude checkbox. |
| `src/Cli/Command.php` | `wp ai-ready generate` and `wp ai-ready status`. |
| `views/` | Escaped templates. Controllers prepare every value. |
| `uninstall.php` | Site and network cleanup. Not the deactivation hook. |

Class files use PSR-4 names (`MarkdownConverter.php`). `phpcs.xml.dist` turns off the WordPress file-name sniff for that reason. Methods stay `snake_case`.

## PHP 7.4

The supported floor is PHP 7.4. Do not use union types, `mixed`, `str_contains`, constructor promotion, named arguments, enums, or `match`. Typed properties and arrow functions are fine. `composer.json` pins the platform to 7.4.33 so the lockfile installs on the oldest CI job.

## Routing

Activation and a version bump call `flush_rewrite_rules()`. The rules are:

- `^llms\.txt$`
- `^llms-full\.txt$`
- `^(.+?)\.md$`

`parse_request` sets the same query vars when the request path matches, so a file can be served after a permalink change even if the rewrite list is stale, as long as the request reaches WordPress. Canonical redirects and 404 guessing are disabled for those requests so `.md` is not redirected back to the HTML permalink. Deactivation removes only those three rules.

`index.md` resolves to the static front page. Paths containing `..` or a NUL byte are rejected. `?format=markdown` is a public read of an already public document and does not use a nonce.

## Publication

`Publication::is_public_post()` is the gate for Markdown. `is_discoverable_post()` also requires discovery to be on and is the gate for the index. Filters may return false. A true return cannot override a structural rejection.

Discovery modes:

- `follow` publishes `/llms.txt` when `blog_public` is `1`.
- `enabled` always publishes it.
- `disabled` returns 404 for both index URLs and removes their stored copies.

`Generator::generate()` asks `discovery_enabled()` before `Store::put_indexes()`. A false answer deletes the transient and the upload files.

## Cache

Index files are deleted on unpublish, exclusion, robots changes, and settings changes. The cron event only warms the cache after that. A lock is an option (`ai_ready_wp_lock_*`) taken with `add_option`. A lock older than 30 seconds is treated as abandoned. Releasing a lock deletes only the current request's key.

Markdown transients store `[ fingerprint => body ]`. The fingerprint includes the blog ID, plugin version, post ID, `post_modified_gmt`, and a hash of the title, excerpt, and content. Callers must check eligibility before reading the cache.

Output caps are 512 KiB per document, 1 MiB for `llms.txt`, and 2 MiB for `llms-full.txt`. Overflow is cut on a newline and marked truncated.

## URL policy and sanitizing

`UrlPolicy` accepts `http`, `https`, `mailto`, and `tel` for links, and `http` and `https` for images. Relative URLs are allowed. The check decodes the candidate several times, strips control characters, and rejects a backslash. Anything else fails closed.

`Sanitizer` is applied to every public body after filters. It rejects invalid UTF-8, caps the size, strips tags outside fenced code, and removes links or images whose destination fails the policy. Code fences are left intact so a document can still mention a dangerous URL as text.

`the_content` runs in the current process. The oEmbed auto-embed callback is removed for that pass so generation does not make HTTP requests.

## Commands

```bash
composer install
composer lint
composer test
composer check
```

PHPUnit covers the converter, the llms.txt builder, the URL policy, the sanitizer, robots meta shapes, exclusions, and the settings sanitizer. Those tests do not boot WordPress. `tests/bootstrap.php` defines `ABSPATH` so class files can keep their direct-access guard.

A full site is available with wp-env when Docker is installed:

```bash
npx @wordpress/env start
npx @wordpress/env run cli wp plugin activate ai-ready-wp
npx @wordpress/env run cli wp ai-ready status
```

`.wp-env.json` uses PHP 8.2 and mounts this directory as a plugin.

## Internationalization

The text domain is `ai-ready-wp`. Translations load on `init` at priority 0. The `Optional` section heading is a spec token and is not passed through gettext.

## Uninstall

`uninstall.php` runs only when the plugin is deleted. It loops every site on a network, then deletes options, cron, exclusion and noindex meta, database transients, lock options, and `uploads/ai-ready-wp`. Transients stored in an external object cache expire by their TTL. Deactivation leaves settings in place and only clears rewrites and the cron event.

## Release archive

`.gitattributes` marks tests, CI, coding-standard config, `composer.lock`, and this file `export-ignore`. Do not commit `vendor/`.
