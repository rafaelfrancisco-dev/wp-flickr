# WP Flickr Portfolio Development Notes

This document describes the current implementation of the `WP Flickr Portfolio` plugin in this repository.

It is intended for future development, maintenance, and deployment work rather than end-user marketing.

## Overview

The plugin integrates a WordPress portfolio site with Flickr and supports two display models:

- Remote rendering: fetch metadata from Flickr, render Flickr-hosted image URLs directly on the frontend, and rely on Meow Lightbox to enhance the gallery markup.
- Media sync: import Flickr images into the WordPress Media Library so the resulting attachments behave like native WordPress media and can be used naturally by Meow Gallery.

The current implementation supports:

- Flickr albums/photosets
- Flickr photostreams
- Public access with API key/secret
- Private access through Flickr OAuth 1.0a with read permissions
- Cached Flickr responses using WordPress transients
- Manual per-page sync from the admin UI
- Bulk all-pages import for a Flickr source
- Optional metadata refresh for already imported attachments
- Optional per-source daily scheduled sync using WP-Cron
- Rolling plugin-owned operational logs with an admin viewer
- Shortcode-based frontend output
- Gutenberg block-based editor insertion
- Wide and full-width Gutenberg block alignment support
- Optional text-below-image captions with masonry forcing captions off
- Two supported layouts: uniform `grid` and variable-height `masonry`
- Server-rendered frontend pagination with gallery-specific query args

## Repository Structure

`wp-flickr-portfolio.php`

- Main plugin bootstrap.
- Defines constants.
- Loads plugin classes.
- Registers activation defaults.

`includes/class-wp-flickr-portfolio-plugin.php`

- Initializes the plugin services.
- Registers the frontend stylesheet.

`includes/class-wp-flickr-portfolio-settings.php`

- Owns plugin settings.
- Registers the top-level admin screen.
- Renders admin UI for credentials, OAuth actions, cache clearing, manual sync, bulk sync, scheduled sync management, and log viewing.

`includes/class-wp-flickr-portfolio-logger.php`

- Persists a capped rolling history of operational log entries in WordPress.
- Sanitizes log context before persistence.
- Feeds the plugin Logs admin subpage.

`includes/class-wp-flickr-portfolio-oauth.php`

- Implements Flickr OAuth 1.0a signing helpers.
- Builds Authorization headers and request signatures.

`includes/class-wp-flickr-portfolio-api.php`

- Normalizes source arguments.
- Starts and completes the OAuth flow.
- Calls Flickr REST endpoints.
- Caches gallery payloads.
- Maps Flickr API responses into a normalized internal gallery payload.

`includes/class-wp-flickr-portfolio-media-sync.php`

- Imports Flickr images into the WordPress Media Library.
- Avoids duplicate imports by tracking Flickr photo IDs in attachment meta.
- Stores synced attachment ID lists keyed by source.
- Loads WordPress media sideload dependencies explicitly.
- Prefers Flickr's full/original image when available and suppresses intermediate image-size generation to keep imports within typical shared-host limits while preserving the original file bytes.
- Stores scheduled-source records keyed by logical source.
- Runs bulk sync jobs manually and through WP-Cron.

`includes/class-wp-flickr-portfolio-shortcode.php`

- Registers `[flickr_portfolio]`.
- Registers the Gutenberg block.
- Chooses between remote and synced rendering.
- Emits `.gallery` markup for Meow Lightbox compatibility.

`assets/wp-flickr-portfolio.css`

- Provides lightweight gallery styling for remote-rendered Flickr grids.

## Current Plugin Behavior

### Settings

The plugin stores its main settings in the `wpfp_settings` option.

Current settings include:

- `api_key`
- `api_secret`
- `oauth_token`
- `oauth_token_secret`
- `user_nsid`
- `username`
- `fullname`
- `cache_ttl`
- `default_mode`
- `default_per_page`
- `default_layout`
- `lightbox_selector`

Related options:

- `wpfp_request_token`: temporary Flickr request token data during OAuth
- `wpfp_cache_keys`: known transient keys for cache invalidation
- `wpfp_synced_sources`: mapping of normalized source keys to imported attachment IDs
- `wpfp_scheduled_sources`: registry of scheduled sync sources and their latest run state
- `wpfp_logs`: rolling plugin-owned operational log entries

## Recent Notes

- `0.9.0` removed `mode="lazy"` and all associated background-sync machinery (WP-Cron per-photo batching, lazy payload transients, original-upgrade jobs, frontend JS poller). The supported sync paths are now manual per-page sync, bulk all-pages import, and scheduled daily sync from the admin screen.
- `0.7.1` hardened frontend media imports by explicitly loading `download_url()`, `media_handle_sideload()`, and attachment metadata helpers before sideloading Flickr images. This avoids hard failures where one helper had been loaded by WordPress or another plugin but the full import stack had not.

### OAuth Flow

The admin screen exposes operational actions for:

- Connect Flickr
- Disconnect Flickr
- Clear Cached Flickr Data
- View and clear plugin logs
- Manual page sync
- Bulk import all pages
- Scheduled source management

OAuth flow:

1. Admin enters Flickr API key and secret.
2. Plugin requests a Flickr request token.
3. Admin is redirected to Flickr authorization.
4. Flickr redirects back to the plugin admin page callback URL.
5. Plugin exchanges the verifier for an access token and stores the Flickr identity.

The callback URL is generated from the site admin URL and must be registered in the Flickr app settings.

### Flickr Sources

Two source types are supported:

- `photoset`
- `photostream`

Normalized source arguments currently include:

- `source`
- `user_id`
- `photoset_id`
- `per_page`
- `page`
- `privacy_filter`

The plugin requires `photoset_id` when `source="photoset"`.

If `user_id` is omitted but the plugin is authenticated, the stored Flickr `user_nsid` is used.

### Caching

Gallery payloads are cached with WordPress transients.

Cache key inputs:

- normalized source arguments
- whether the request is public or authenticated

The admin screen includes a manual cache clear action that deletes all tracked transient keys.

### Operational Logging

The plugin now records a capped rolling history of operational log entries in `wpfp_logs`.

Log entries include:

- timestamp
- level
- component
- event key
- message
- compact sanitized context

The Logs admin subpage is intended to explain:

- Flickr cache hits and misses
- rate-limit retries
- shortcode render-mode decisions
- manual, lazy, and scheduled sync activity
- attachment import outcomes
- operational errors without exposing secrets

### Remote Rendering

Remote rendering is the default mode.

Behavior:

- Fetch photo metadata and URLs from Flickr.
- Preserve image dimensions from Flickr size metadata so the frontend can keep the right aspect ratio.
- Render gallery markup with the `.gallery` class.
- Use display-sized image URLs in `<img>` elements.
- Use full image URLs in anchor tags.
- Include caption/title metadata in rendered attributes and captions.
- Use the plugin's own grid or masonry wrappers instead of faking masonry with CSS spans.
- Render pagination links when Flickr reports more than one page.

This is designed so Meow Lightbox can attach to the output without requiring a custom integration layer.

### Media Sync Rendering

Media sync is attachment-based.

Behavior:

- Download each Flickr image through WordPress sideloading.
- Store `_wpfp_flickr_photo_id` on the attachment.
- Reuse an existing attachment when the Flickr photo ID already exists.
- Optionally refresh metadata on existing attachments during manual, bulk, or scheduled sync.
- Store imported attachment IDs for a normalized per-page source key.
- Store scheduled-source records using a logical source key that excludes `page`.
- Render synced galleries through the same plugin gallery structure used by remote mode.
- Reuse the same frontend pagination component, with exact-page attachment lookup driven by the stored source key and attachment metadata fallback.
- Pace uncached Flickr REST sync requests conservatively to stay under Flickr's documented shared API-key query limit.

This makes the imported content behave like normal WordPress media and gives Meow Gallery a native path to work with the images.

## Shortcode Contract

The plugin currently exposes:

```text
[flickr_portfolio]
```

Supported shortcode attributes:

- `source`: `photoset` or `photostream`
- `user_id`: Flickr NSID
- `photoset_id`: required for `photoset`
- `per_page`: items per page
- `page`: result page
- `privacy_filter`: Flickr privacy filter from `1` to `5`
- `mode`: `remote`, `media`, or `auto`
- `layout`: `grid` or `masonry`
- `columns`: remote grid column count
- `captions`: `true` or `false`
- `class`: extra CSS classes
- `title`: optional display title override

Legacy `layout="tiles"` content is normalized to `masonry` at render time.

Mode semantics:

- `remote`: always render Flickr-hosted images
- `media`: render imported attachments only for the requested page; show an error block when that page has not been synced yet
- `auto`: render imported attachments when available, otherwise fall back to remote Flickr output

Pagination semantics:

- `page` is the initial page value for the gallery.
- Frontend pagination uses gallery-specific query parameters such as `wpfp_gallery_1_page=2`.
- Multiple Flickr galleries can coexist on one page without sharing pagination state.

Examples:

```text
[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001"]
```

```text
[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18"]
```

```text
[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" mode="auto"]
```

## Gutenberg Block

The plugin registers a dynamic block:

`wp-flickr-portfolio/gallery`

Current block behavior:

- Uses a native editor sidebar UI for source, mode, layout, title, privacy, page, and per-page settings.
- Uses server-side rendering in the editor preview.
- Reuses the same PHP rendering path as the shortcode, so frontend output stays consistent between shortcode and block usage.
- Supports Gutenberg `wide` and `full` alignment options.
- Disables text-below-image captions automatically when the block uses masonry layout.
- Exposes only `Grid` and `Masonry`; legacy `tiles` blocks preview and render as masonry.
- Treats the `Page` control as the initial page rather than a fixed non-interactive slice.

## Meow Integration Notes

### Meow Lightbox

Primary frontend integration is through compatible markup, not through Meow internals.

The plugin deliberately renders:

- `.gallery`
- `.gallery-item`
- `.gallery-icon`
- file links to full-size images

That keeps the integration low-risk because Meow Lightbox already supports gallery-like frontend markup.

### Meow Gallery

The plugin does not currently call Meow Gallery internals or private APIs.

Instead, it supports Meow Gallery through imported attachments:

- Sync Flickr images into the Media Library.
- Use those attachments inside Meow Gallery like any other uploaded image set.

This is the safer integration path for now because it avoids coupling to undocumented plugin internals.

## Operational Notes

### Installation

1. Upload the plugin to the WordPress site.
2. Activate it.
3. Open `WP Flickr Portfolio` from the admin sidebar.
4. Enter Flickr API key and secret.
5. Register the callback URL shown by the plugin inside the Flickr app settings if private access is needed.
6. Connect Flickr.

### Packaging

The repository currently includes an installable ZIP:

`wp-flickr-portfolio-0.9.0.zip`

That ZIP contains the expected top-level plugin folder:

`wp-flickr-portfolio/`

### Validation Completed

All PHP files pass syntax linting (`php -l`).

## Known Limitations

- No automated tests yet.
- Scheduled sync relies on WP-Cron, so timing depends on site traffic unless a real cron runner is configured.
- No direct Meow Gallery API integration; attachment-based compatibility is used instead.
- Error handling is functional but basic; errors are shown as user-facing blocks or admin notices.

## Recommended Next Work

- Add source presets in admin rather than requiring manual shortcode composition.
- Add automated tests against a WordPress test environment.
- Add better attachment grouping metadata so synced sources can be managed more easily in wp-admin.
- Evaluate whether Meow Gallery exposes a stable public extension point worth integrating directly.
