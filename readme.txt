=== WP Flickr Portfolio ===
Contributors: rafaelfrancisco
Tags: flickr, gallery, portfolio, meow gallery, meow lightbox
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch Flickr albums and photostream images into WordPress, with remote rendering for Meow Lightbox and optional media sync for Meow Gallery.

== Description ==

WP Flickr Portfolio connects a WordPress portfolio site to Flickr.

Features:

- OAuth 1.0a connection flow for Flickr private read access.
- Shortcode-driven remote Flickr galleries.
- Native Gutenberg block for inserting Flickr galleries visually.
- Manual per-page sync and bulk all-pages import into the WordPress Media Library.
- Lazy per-page sync triggered from frontend gallery views, with frontend updates that swap remote items to local attachment URLs after the background import starts landing.
- Optional metadata refresh for already imported Flickr attachments.
- Optional daily scheduled sync for selected Flickr sources with background run-now support.
- Dedicated admin log viewer for recent plugin activity.
- Gallery markup that fits Meow Lightbox's default selectors.
- Attachment-based galleries that work naturally with Meow Gallery after sync.
- Frontend pagination links that work without JavaScript.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-flickr-portfolio/`.
2. Activate the plugin in WordPress.
3. Open `WP Flickr Portfolio` from the WordPress admin sidebar.
4. Add your Flickr API key and secret.
5. Configure your Flickr app's OAuth callback URL to the value shown on the settings page.
6. Connect Flickr if you need private content access.
7. Use the `Logs` subpage to inspect recent plugin activity when debugging sync behavior.

== Frequently Asked Questions ==

= Does this work with public Flickr photos only? =

Yes. Public sources work with a valid API key and secret even if you do not connect a Flickr account.

= How do Meow Gallery and Meow Lightbox fit in? =

Remote galleries render `.gallery` markup so Meow Lightbox can attach on the frontend. If you sync Flickr photos into the Media Library, the imported attachments can be used by Meow Gallery like normal WordPress media.

= What shortcode should I use? =

Photoset example:

[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001"]

Photostream example:

[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18"]

Imported-attachment example:

[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" mode="auto"]

Lazy-sync example:

[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18" mode="lazy"]

Captionless example:

[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" captions="false"]

= How does media sync work? =

You can sync one Flickr result page, import all pages for a source, configure a daily scheduled sync for selected sources, or let `mode="lazy"` queue a one-page import after a visitor opens an unsynced gallery page. In lazy mode the page renders remote Flickr items immediately, then the browser swaps those items to local WordPress attachment URLs as the background import finishes. Lazy mode imports the display-sized Flickr file first for speed and can then upgrade the local attachment file to Flickr's original image in the background. Existing imports are matched by Flickr photo ID to avoid duplicates, refresh mode updates metadata on those existing attachments, and local gallery modes are matched against the exact source configuration including `per_page` and `page`.

= Is there a Gutenberg block? =

Yes. The plugin registers a `Flickr Portfolio` block so editors can configure galleries from block controls instead of typing shortcode manually. The block can also hide text below images, masonry layout forces that text off automatically, and the `Page` control sets the starting page before visitors use frontend pagination links.

= Is there a debug log viewer? =

Yes. The plugin includes a `Logs` admin subpage that keeps a rolling in-database history of recent operational events such as Flickr API requests, sync queueing, lazy mode decisions, imports, retries, and failures.

== Changelog ==

= 0.8.1 =

- Changed lazy sync to import the display-sized Flickr file first so remote items can switch to local attachments quickly.
- Added background per-photo upgrades from the display-sized import to Flickr's original file after lazy sync has already localized the gallery.

= 0.8.0 =

- Changed `mode="lazy"` to render immediately with remote Flickr URLs, queue the import in the background, and progressively replace items with local attachment URLs in the browser as imports complete.
- Restored preference for Flickr's full/original image file during Media Library imports when that URL is available.
- Stored synced-page attachment mappings incrementally during import so the lazy frontend can observe progress while the worker is still running.

= 0.7.2 =

- Changed Flickr media imports to prefer the display-size URL instead of the largest original file when building WordPress attachments.
- Disabled intermediate image-size generation during Flickr sideload imports to reduce memory/time pressure on lazy and background syncs.
- Added info-level import-step logs for download and sideload start so import progress is visible in the Logs screen.

= 0.7.1 =

- Fixed lazy and other Media Library import paths on frontend requests by loading WordPress sideload dependencies explicitly instead of assuming `download_url()` is already available.
- Added per-photo import start/error logging and throwable logging so the Logs screen captures unexpected import failures instead of stopping at payload start.

= 0.7.0 =

- Added a dedicated `Logs` admin subpage for recent plugin activity.
- Added plugin-owned rolling operational logs stored in WordPress.
- Logged Flickr API requests, rate-limit retries, sync jobs, lazy/media mode decisions, and import outcomes.

= 0.6.2 =

- Changed `mode="lazy"` to try an immediate one-page import during frontend render before falling back to a background retry.

= 0.6.1 =

- Changed `mode="media"` to be strict local-only output for unsynced pages.
- Repaired attachment-backed gallery lookup by falling back to stored source metadata when the page mapping option is stale.
- Moved scheduled `Run Now` execution into background WP-Cron jobs and added queued/running status messages.
- Paced uncached Flickr sync requests conservatively against Flickr's documented per-key rate guidance.

= 0.6.0 =

- Added `mode="lazy"` to queue one-page media imports from frontend gallery views.
- Added background lazy-sync support in the shortcode and Gutenberg block UIs.
- Added Flickr rate-limit retries and duplicate-run guards for scheduled syncs.

= 0.5.0 =

- Moved WP Flickr Portfolio from the Settings menu to its own top-level admin sidebar item.
- Added bulk import for all Flickr pages of a source.
- Added optional refresh behavior for already imported Flickr attachments.
- Added daily scheduled sync for selected Flickr sources with run-now, enable/disable, and remove actions.

= 0.4.0 =

- Added server-rendered frontend pagination using gallery-specific query parameters.
- Kept pagination working across remote, media, and auto modes by falling back to Flickr URLs for unsynced pages.
- Added shared pagination styling for shortcode and Gutenberg block output.

= 0.3.0 =

- Reworked masonry into a real variable-height layout with no fake tile gaps.
- Removed `tiles` from the UI and treat legacy `tiles` content as masonry for backward compatibility.
- Render synced Media Library galleries with the plugin's own markup so grid and masonry stay consistent across remote and synced modes.

= 0.2.1 =

- Added wide and full-width Gutenberg block alignment support.
- Added explicit text-below-image suppression and automatic caption disabling for masonry layouts.

= 0.2.0 =

- Added Gutenberg block support for Flickr galleries.

= 0.1.0 =

- Initial release.
