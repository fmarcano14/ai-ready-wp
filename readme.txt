=== AI Ready WP ===
Contributors: fmarcano14
Tags: markdown, content, seo
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Single-site only. Publishes llms.txt and Markdown views of public posts and pages.

== Description ==

AI Ready WP writes three kinds of files on your server:

* `/llms.txt`, with the site name, a short summary, and links to the public documents.
* `/llms-full.txt`, with the Markdown of those documents, when you leave that option on.
* A Markdown view of each included post or page at its permalink plus `.md`, or `?format=markdown` when the permalink already has a query string.

The Markdown response uses `text/markdown; charset=utf-8`, `X-Robots-Tag: noindex`, and a canonical link back to the HTML page. The HTML head links to that Markdown URL.

This is a single-site plugin. It does not run on multisite, and it does not call an external service. It needs the PHP mbstring extension. If mbstring is missing, WordPress shows an admin notice and the plugin does not load.

Drafts, private posts, scheduled posts, and password-protected posts are left out. Each document has an "Exclude from AI files" checkbox. Common SEO noindex flags are honored.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/ai-ready-wp`.
2. Activate it on a single site. Network activation is refused.
3. Open Settings > AI Ready and press Generate files.

== Frequently Asked Questions ==

= Does this run on multisite? =

No. AI Ready WP is a single-site plugin. Activating it on a network stops and shows an error.

= Does it send content anywhere? =

No. llms.txt and each Markdown document are stored in the database on this server. llms-full.txt is streamed from those documents when it is requested. The plugin serves them only through its own URLs.

= When do the files refresh? =

A normal edit schedules one rebuild about 30 seconds later. Unpublishing, adding a password, excluding a document, or marking it noindex rewrites the files in that request. A normal page view serves the last generated files and does not rebuild them.

= Who can change the settings? =

Settings require the `manage_options` capability and a nonce. The exclude checkbox requires `edit_post` on that document.

== Screenshots ==

1. Settings > AI Ready, where the files are generated.

== Changelog ==

= 1.0.0 =
* First release. Single-site only.
