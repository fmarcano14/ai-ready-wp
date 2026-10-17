=== AI Ready WP ===
Contributors: fmarcano14
Donate link: https://fer.marketing
Tags: ai, markdown, seo, content, developer
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish /llms.txt and a clean Markdown version of every public document, entirely inside wp-admin.

== Description ==

AI Ready WP makes a WordPress site readable by AI assistants without sending content to an external service.

* `/llms.txt` follows the llms.txt shape: a site title, a blockquote summary, optional details, and sections of Markdown links.
* `/llms-full.txt` is an optional concatenation of those documents.
* Each public post or page is also available as Markdown at its permalink plus `.md`, or with `?format=markdown` on plain permalinks.
* Public documents advertise the files with `rel="describedby"` and `rel="alternate" type="text/markdown"`.

The index respects the content visitors can already read. Password-protected, private, excluded, and noindex documents stay out. Discovery follows Settings → Reading unless you force it on or off. Markdown endpoints are a separate switch.

Generation runs in PHP. Publishing or updating a document drops the cache immediately and schedules one WP-Cron rebuild. **Settings → AI Ready** includes a live preview and a **Generate now** button.

WP-CLI:

`wp ai-ready generate`
`wp ai-ready status`

Fernando Marcano — Fer LLC (https://fer.marketing)

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/ai-ready-wp/`, or install the zip through Plugins → Add New.
2. Activate AI Ready WP. Activation registers the rewrite rules.
3. Open Settings → AI Ready and choose Generate now.

Pretty permalinks are recommended. Plain permalinks still serve `?format=markdown` for any request that reaches WordPress.

== Frequently Asked Questions ==

= Does this send content to an AI service? =

No. The Markdown conversion and the index are built on the site.

= Which documents are included? =

Published documents of the post types you select, except password-protected items, private items, the posts page, WooCommerce cart, checkout, and account pages, documents marked noindex by common SEO plugins, and anything you exclude by ID, slug, path, or the per-document AI Ready box.

= What does Optional mean? =

Post types checked as Optional are grouped under a section titled Optional. That heading is part of the llms.txt convention, so it is not translated. Agents may skip it.

= Why is /llms.txt a 404 while the site is live? =

Discovery defaults to following Settings → Reading. While "Discourage search engines" is checked, the index and the describedby link stay off. Choose "Always publish" to force the index on. Turning discovery off also deletes the copies stored under uploads.

= Does uninstall remove data? =

Yes. Deleting the plugin removes its options, transients stored in the database, exclusion meta, the cron event, and `uploads/ai-ready-wp`. An external object cache can keep a transient until its lifetime ends.

== Screenshots ==

1. Settings → AI Ready, with discovery controls, document selection, and a live llms.txt preview.

== Changelog ==

= 1.0.0 =
* First release. `/llms.txt`, optional `/llms-full.txt`, per-document Markdown, settings, per-document exclusion, and WP-CLI.

== Upgrade Notice ==

= 1.0.0 =
First release.
