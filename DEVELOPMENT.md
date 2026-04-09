# WP Flickr Portfolio Development Notes

This document describes the current implementation of the `WP Flickr Portfolio` plugin in this repository.

It is intended for future development, maintenance, and deployment work rather than end-user marketing.

## Overview

The plugin integrates a WordPress portfolio site with Flickr and renders remote galleries with a built-in EXIF lightbox.

The current implementation supports:

- Flickr albums/photosets
- Flickr photostreams
- Public access with API key/secret
- Private access through Flickr OAuth 1.0a with read permissions
- Cached Flickr responses using WordPress transients
- Built-in lightbox with full-size image display and EXIF metadata
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
- Registers the frontend stylesheet and lightbox script.

`includes/class-wp-flickr-portfolio-settings.php`

- Owns plugin settings.
- Registers the top-level admin screen.
- Renders admin UI for credentials, OAuth actions, cache clearing, and log viewing.
- Logs subpage supports paginated browsing (25 entries per page) and exporting filtered logs as a downloadable text file.

`includes/class-wp-flickr-portfolio-logger.php`

- Persists a capped rolling history of operational log entries in WordPress.
- Sanitizes log context before persistence.
- Supports filtered entry retrieval with offset-based pagination.
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
- Provides an AJAX endpoint for fetching photo EXIF data (`flickr.photos.getExif` and `flickr.photos.geo.getLocation`).

`includes/class-wp-flickr-portfolio-shortcode.php`

- Registers `[flickr_portfolio]`.
- Registers the Gutenberg block.
- Renders remote Flickr galleries with lightbox data attributes.
- Enqueues the lightbox script and localizes AJAX configuration.

`assets/wp-flickr-portfolio.css`

- Provides lightweight gallery styling for remote-rendered Flickr grids.
- Provides full lightbox overlay styling.

`assets/wp-flickr-portfolio-lightbox.js`

- Vanilla JS lightbox: full-screen overlay, full-size image display.
- Fetches EXIF data via AJAX when the lightbox opens.
- Renders EXIF info in a bottom bar (camera, lens, aperture, shutter, ISO, focal length, location).
- Shows a "View on Flickr" link in the header.
- Supports prev/next navigation with arrow buttons and keyboard arrows.
- Escape key and click-outside close the lightbox.

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
- `default_per_page`
- `default_layout`

Related options:

- `wpfp_request_token`: temporary Flickr request token data during OAuth
- `wpfp_cache_keys`: known transient keys for cache invalidation
- `wpfp_logs`: rolling plugin-owned operational log entries

## Recent Notes

- `0.16.1` increased the default cache TTL from 15 minutes to 24 hours to conserve the Flickr API rate limit (3 600 requests/hour). Centered gallery pagination. Fixed lightbox background click to close when clicking the area around the image.
- `0.16.0` added a built-in lightbox with full-size image display, EXIF metadata (camera, lens, aperture, shutter speed, ISO, focal length, location), a Flickr link, and prev/next keyboard navigation. Removed Meow Lightbox support, media library import, scheduled sync, and all associated settings.

### OAuth Flow

The admin screen exposes operational actions for:

- Connect Flickr
- Disconnect Flickr
- Clear Cached Flickr Data
- View, filter, paginate, export, and clear plugin logs

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

Gallery payloads and EXIF data are cached with WordPress transients.

The default cache TTL is 86 400 seconds (24 hours), configurable from the admin screen with a minimum of 3 600 seconds (1 hour). Flickr limits each API key to 3 600 requests per hour so aggressive caching is important.

Cache key inputs:

- normalized source arguments
- whether the request is public or authenticated

The admin screen includes a manual cache clear action that deletes all tracked transient keys.

### Operational Logging

The plugin records a capped rolling history of operational log entries in `wpfp_logs`.

Log entries include:

- timestamp
- level
- component
- event key
- message
- compact sanitized context

The Logs admin subpage supports:

- Filtering by level and component
- Paginated browsing with 25 entries per page and WordPress-style pagination links
- Exporting filtered or all log entries as a downloadable text file
- Clearing all retained log entries

### Remote Rendering

Remote rendering is the only mode.

Behavior:

- Fetch photo metadata and URLs from Flickr.
- Preserve image dimensions from Flickr size metadata so the frontend can keep the right aspect ratio.
- Render gallery markup with the `.gallery` class.
- Use display-sized image URLs in `<img>` elements.
- Use full image URLs in anchor tags.
- Include caption/title metadata in rendered attributes and captions.
- Use the plugin's own grid or masonry wrappers instead of faking masonry with CSS spans.
- Render pagination links when Flickr reports more than one page.
- Anchor tags include `data-wpfp-photo-id`, `data-wpfp-full-url`, `data-wpfp-title`, and `data-wpfp-owner` attributes for lightbox integration.

### Lightbox

Clicking a gallery image opens the built-in lightbox:

- Full-screen dark overlay with the full-size image centered.
- Bottom bar shows EXIF metadata fetched via AJAX (`wpfp_get_exif`).
- EXIF data includes camera, lens, aperture, shutter speed, ISO, focal length, and location.
- Header has a close button and a "View on Flickr" link.
- Prev/next arrow buttons navigate through gallery images.
- Keyboard support: Left/Right arrows for prev/next, Escape to close.
- Click outside the image to close.
- Loading spinner while the full-size image loads.
- EXIF data is cached per photo ID in the browser and in WordPress transients.

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
- `layout`: `grid` or `masonry`
- `columns`: remote grid column count
- `captions`: `true` or `false`
- `class`: extra CSS classes
- `title`: optional display title override

Legacy `layout="tiles"` content is normalized to `masonry` at render time.

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

## Gutenberg Block

The plugin registers a dynamic block:

`wp-flickr-portfolio/gallery`

Current block behavior:

- Uses a native editor sidebar UI for source, layout, title, privacy, page, and per-page settings.
- Uses server-side rendering in the editor preview.
- Reuses the same PHP rendering path as the shortcode, so frontend output stays consistent between shortcode and block usage.
- Supports Gutenberg `wide` and `full` alignment options.
- Disables text-below-image captions automatically when the block uses masonry layout.
- Exposes only `Grid` and `Masonry`; legacy `tiles` blocks preview and render as masonry.
- Treats the `Page` control as the initial page rather than a fixed non-interactive slice.

## Operational Notes

### Installation

1. Upload the plugin to the WordPress site.
2. Activate it.
3. Open `WP Flickr Portfolio` from the admin sidebar.
4. Enter Flickr API key and secret.
5. Register the callback URL shown by the plugin inside the Flickr app settings if private access is needed.
6. Connect Flickr.

### Packaging

The repository includes installable ZIPs at the root, one per release. Each ZIP contains the expected top-level plugin folder:

`wp-flickr-portfolio/`

### Validation Completed

All PHP files pass syntax linting (`php -l`).

## Known Limitations

- No automated tests yet.
- Error handling is functional but basic; errors are shown as user-facing blocks or admin notices.

## Recommended Next Work

- Add source presets in admin rather than requiring manual shortcode composition.
- Add automated tests against a WordPress test environment.
