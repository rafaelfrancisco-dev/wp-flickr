=== WP Flickr Portfolio ===
Contributors: rafaelfrancisco
Tags: flickr, gallery, portfolio, meow gallery, meow lightbox
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.14.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch Flickr albums and photostream images into WordPress, with remote rendering for Meow Lightbox and optional scheduled media sync for Meow Gallery.

== Description ==

WP Flickr Portfolio connects a WordPress portfolio site to Flickr.

Features:

- OAuth 1.0a connection flow for Flickr private read access.
- Shortcode-driven remote Flickr galleries.
- Native Gutenberg block for inserting Flickr galleries visually.
- Scheduled sync for selected Flickr sources with immediate admin-side Run Now support, replacing manual per-page and bulk import.
- Optional metadata refresh for already imported Flickr attachments.
- Optional recurring scheduled sync for selected Flickr sources with immediate admin-side Run Now support.
- Dedicated admin log viewer for recent plugin activity.
- Paginated log browsing with level and component filters.
- Export filtered log entries as a downloadable text file.
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

Captionless example:

[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" captions="false"]

= How does media sync work? =

Add a scheduled source for a Flickr photoset or photostream, then use Run Now to import all pages on demand. Existing imports are matched by Flickr photo ID to avoid duplicates, refresh mode updates metadata on those existing attachments, and local gallery modes are matched against the exact source configuration including `per_page` and `page`.

= Is there a Gutenberg block? =

Yes. The plugin registers a `Flickr Portfolio` block so editors can configure galleries from block controls instead of typing shortcode manually. The block can hide text below images, masonry layout forces that text off automatically, and the `Page` control sets the starting page before visitors use frontend pagination links.

= Is there a debug log viewer? =

Yes. The plugin includes a `Logs` admin subpage that keeps a rolling in-database history of recent operational events such as Flickr API requests, sync queueing, lazy mode decisions, imports, retries, and failures. The log viewer supports paginated browsing (25 entries per page), filtering by level and component, and exporting filtered entries as a text file.

== Changelog ==

= 0.14.1 =

- Stopped refetching the same Flickr page on every rate-limit retry by reusing the cached page payload while a background sync waits to resume.
- Increased deferred `429 Too Many Requests` backoff delays materially and capped repeated rate-limit loops with a clearer host-blocked failure message.
- Kept the queued background sync flow from `0.14.0`, but made its rate-limit behavior less noisy and less wasteful.

= 0.14.0 =

- Changed `Run Now` to queue scheduled sync work in the background instead of holding the admin request open until the import finishes.
- Split scheduled sync into resumable background batches with persisted per-source progress, plus an auto-refreshing progress bar in the settings table.
- Changed Flickr original-download `429 Too Many Requests` handling to reschedule the next batch after a bounded delay instead of retrying the same photo repeatedly in one request.

= 0.13.1 =

- Extended the plugin's shared Flickr pacing to cover original-file downloads as well as REST API requests, reducing bursty request patterns that can trigger `429` responses.
- Added bounded retry/backoff for Flickr original downloads that return `429 Too Many Requests`, with log entries that show the retry delay and attempt number.
- Updated sync behavior/docs so `Run Now` failures surface Flickr throttling more explicitly.

= 0.13.0 =

- Replaced the original-file import transport with a plugin-owned downloader that streams to a controlled temp file, validates the HTTP response and downloaded bytes, and logs detailed response metadata.
- Added a cURL retry path for Flickr original downloads when the default WordPress HTTP transport fails.
- Improved failure messages so scheduled sync errors identify the exact Flickr photo that stopped the run.

= 0.12.3 =

- Changed the admin `Run Now` action to execute immediately in the current admin request instead of queueing a background WP-Cron loopback.
- Scheduled recurring syncs now start on their real interval instead of auto-firing one minute after saving a source.
- Updated sync notices and docs to reflect the foreground `Run Now` behavior.

= 0.12.2 =

- Increased the runtime budget for large original-file imports during scheduled sync, including longer download timeouts and WordPress/PHP runtime extension requests.
- Added an `import_download_complete` log entry so large-file imports can be diagnosed more precisely when background jobs stall.

= 0.12.1 =

- Changed Media Library imports to require Flickr's original file by default instead of silently falling back to a smaller display-size image.
- Added a settings toggle to allow resized Flickr fallback imports when the original file cannot be downloaded.
- Clarified import failure logging when the original file is unavailable and resized fallback is disabled.

= 0.12.0 =

- Fixed scheduled sync error handlers to write log entries before redirecting, so the Logs page always shows the reason for a failed save, remove, or toggle.
- Added the specific error detail (e.g. missing photoset ID, missing user ID) to the admin notice when saving a scheduled source fails.

= 0.11.0 =

- Removed the Manual Media Sync and Import All Pages admin sections. Use the Scheduled Sync section to add a source, then click Run Now to import on demand.
- Removed the per-page sync and bulk all-pages admin-post handlers and their associated dead code.
- Cleaned up stale `mode="lazy"` references from documentation.

= 0.10.0 =

- Added paginated log browsing (25 entries per page) with WordPress-style pagination links.
- Added log export as a downloadable text file, respecting active level and component filters.
- Removed the redundant View Logs button from the main settings page (the sidebar Logs submenu already links there).

= 0.9.0 =

- Removed `mode="lazy"` and all associated background sync machinery. Use manual per-page sync, bulk all-pages import, or scheduled sync from the admin screen to import Flickr images into the Media Library.

= 0.8.2 =

- Fixed lazy sync to process one photo per WP-Cron event so large original files do not exhaust the PHP memory or execution-time budget of the background job.
- Fixed lazy sync re-queuing when a previous sync completed only partially, so all photos are eventually localized and updated in the browser.

= 0.7.2 =

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
