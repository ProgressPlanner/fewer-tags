=== Fewer Tags ===
Contributors: joostdevalk, aristath, filipi, progressplanner
Tags: seo, tags, taxonomy, sitemap, archives
Requires at least: 6.2
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 2.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage your site's tags: set minimum post counts, merge terms across taxonomies, and create redirects when deleting or merging terms.

== Description ==

Fewer Tags helps you clean up WordPress tag archives by setting a minimum number of posts a tag needs before it becomes live on your site. It also lets you merge terms across taxonomies and create redirects when you merge or delete terms.

Many WordPress sites collect lots of tags with only one or two posts. That creates thin archive pages, extra URLs for search engines to crawl, and taxonomy pages that often add little value for visitors. Fewer Tags solves that by hiding low-volume tag archives until they have enough posts to be useful.

By default, tags need 10 posts before they are live. You can change that threshold under Settings → Reading.

== What Fewer Tags does ==

When a tag has fewer than the configured number of posts:

* Its tag archive redirects to your homepage.
* It no longer appears in tag listings on your site.
* It is excluded from WordPress core XML sitemaps.
* It is excluded from Yoast SEO XML sitemaps.
* It is excluded from Slim SEO XML sitemaps.

This helps reduce crawl waste while keeping stronger tag archives available.

== Merge terms ==

* Merge tags, categories, and public custom taxonomy terms when you have permission to manage them. Cross-taxonomy merges require all affected content to support the target taxonomy.
* All posts from the source term are moved to the target term.
* A 301 redirect is created from the old term archive to the new one when Redirection or Yoast SEO Premium is active.

== Redirect on delete ==

* When you delete a term, Fewer Tags prompts you to create a redirect to the homepage or another URL. External destinations require administrator permissions.
* Redirects are created via the [Redirection plugin](https://wordpress.org/plugins/redirection/) or Yoast SEO Premium.

== Why site owners use Fewer Tags ==

* Reduce thin, low-value tag archive pages.
* Keep tag archives focused on tags that actually group enough content.
* Make tag management easier by showing which tags are live in the Tags overview.
* Improve clarity for both visitors and search engines.

See [our research on tag usage in WordPress](https://fewertags.com/research/) if you want the background behind this approach.

Watch the plugin walkthrough:
https://www.youtube.com/watch?v=KItn1X1qMas

You can learn more about Fewer Tags on [progressplanner.com](https://progressplanner.com/plugins/fewer-tags/)!

== Installation ==

1. Install Fewer Tags from Plugins → Add New, or upload the plugin files to your `/wp-content/plugins/` directory.
2. Activate the plugin.
3. Go to Settings → Reading.
4. Set the minimum number of posts a tag needs before it becomes live on your site.
5. To create redirects after merging or deleting terms, install and activate the [Redirection plugin](https://wordpress.org/plugins/redirection/) or Yoast SEO Premium.
6. Save your changes.

== Frequently Asked Questions ==

= What happened to Fewer Tags Pro? =

We merged all its features into Fewer Tags in version 2.0. Term merging and redirects after merging or deleting terms are now included for free. We no longer sell a separate Pro plugin.

= Can I safely install this on an existing site? =

Yes. If your site already has many low-value WordPress tags, Fewer Tags will suppress tag archives that fall below your chosen threshold.

= Should I noindex my tag pages too? =

Usually no. Tag archives can still be useful when they collect enough related content. Fewer Tags is designed to keep useful tag archives live while hiding weak ones. What you could (and should) do instead is add descriptions to those tag pages.

= Where do I change the minimum number of posts for a tag? =

Go to Settings → Reading in your WordPress admin and adjust the minimum post count there. Set it to 0 to keep all tag archives live.

= Which sitemap plugins are supported? =

Fewer Tags excludes low-volume tags from WordPress core XML sitemaps, Yoast SEO XML sitemaps, and Slim SEO XML sitemaps.

= Do I need the Redirection plugin or Yoast SEO Premium? =

For the merge and redirect-on-delete features to create actual redirects, you need either the [Redirection plugin](https://wordpress.org/plugins/redirection/) (free) or Yoast SEO Premium installed and activated. Without either plugin, merging and deleting will still work, but no redirects will be created.

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage, and handle security vulnerabilities. [Report a security vulnerability](https://patchstack.com/database/vdp/fewer-tags).

== Screenshots ==

1. Fewer Tags settings on the Settings → Reading screen, where you choose how many posts a WordPress tag needs before it becomes live.
2. The Tags overview shows whether each WordPress tag is live on your site.
3. A supporting quote about better tag usage and cleaner tag archives.

== Changelog ==

= 2.0 =

Major release: term merging and redirects after merging or deleting terms.

New features:

* Merge tags, categories, and supported custom taxonomy terms, including cross-taxonomy merges.
* Redirect on delete — when you delete a term, get prompted to create a redirect to the homepage or another URL. External destinations require administrator permissions.
* Redirect creation via Redirection plugin or Yoast SEO Premium.
* Admin notice when neither Redirection nor Yoast SEO Premium is installed, with install/activate buttons.

Fixes and safeguards:

* Preserve existing settings and pending redirect offers when upgrading.
* Keep the tag threshold disabled when saving zero or negative values.
* Validate taxonomy permissions and reject self-merges or incompatible content before changing assignments.
* Restrict external redirects to administrators and escape merge and redirect controls.
* Support subdirectory installations and plain permalinks in the merge interface and preserve archive query strings in redirects.
* Send homepage redirects to the correct WordPress site URL, including installations in a subdirectory.
* Report redirect failures accurately when no supported redirect plugin is active.

= 1.5.1 =
* Fix: Prevent incorrect `keywords` output types in Yoast SEO schema in one edge case.

= 1.5 =
* Enhancement: Add support for Slim SEO sitemaps, props [Anh Tran](https://profiles.wordpress.org/rilwis/).
* Fix: Reinstate the "View" action for taxonomies other than tags.
* Dev: Update PHPCompatibility, add PHPStan, and fix the resulting issues.

= 1.4.1 =
* Fix: Trigger a rebuild after a WordPress.org plugin build issue.

= 1.4 =
* Change: Rename the option from `joost_min_posts_count` to `fewer_tags` so it is clearer in the database.
* Enhancement: Add uninstall functionality that removes the setting on uninstall.
* Dev: Simplify the autoloader and remove the Composer package requirement.
* Content: Add plugin walkthrough videos to the readme.

= 1.3.3 =
* Fix: Minor stability improvements.

= 1.3.2 =
* Fix: Prevent a fatal error caused by not loading the autoload file.

= 1.3 =
* Enhancement: Filter the Gutenberg terms block when it is showing tags.
* Enhancement: Improve plugin loading performance with minor optimizations.

= 1.2 =
* Release: First release on WordPress.org.

= 1.1 =
* Improvement: Minor header and sanitization improvements.

= 1.0 =
* Release: Initial release on GitHub.

== Upgrade Notice ==

= 2.0 =
Adds term merging and redirects after merging or deleting terms, with safeguards for permissions and existing settings.
