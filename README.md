# WP Flickr Portfolio

WordPress plugin for pulling Flickr albums and photostream images into a portfolio site.

Development and architecture notes live in [DEVELOPMENT.md](./DEVELOPMENT.md).

## Features

- Connect a Flickr account with OAuth 1.0a for public and private read access.
- Render remote Flickr galleries with the `[flickr_portfolio]` shortcode.
- Insert galleries from a native Gutenberg block.
- Built-in lightbox with full-size image display and EXIF metadata (camera, lens, aperture, shutter speed, ISO, focal length, and location).
- Direct link to the original photo on Flickr from the lightbox.
- Keyboard navigation (arrow keys for prev/next, Escape to close) in the lightbox.
- Inspect recent plugin activity from a dedicated admin Logs screen with pagination, filtering, and text-file export.
- Three layout modes: uniform `grid`, aspect-ratio-preserving `masonry`, and edge-to-edge `flush` (no gaps, no rounded corners).
- Frontend pagination with gallery-specific page links.

## Installation

1. Copy this folder into `wp-content/plugins/wp-flickr-portfolio`.
2. Activate `WP Flickr Portfolio`.
3. Go to `WP Flickr Portfolio` in the WordPress admin sidebar.
4. Add your Flickr API key and secret from https://www.flickr.com/services/api/.
5. Configure your Flickr app's OAuth callback URL with the value shown on the plugin settings page.
6. Click `Connect Flickr` if you need private content access.
7. Open the `Logs` subpage when you need to inspect plugin activity or debug issues.

## Shortcode

### Photoset

```text
[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001"]
```

### Photostream

```text
[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18"]
```

`page` sets the initial page to load. Visitors can move between pages from the frontend pagination links, which use gallery-specific query-string parameters.

`captions="false"` hides the text below each image. Masonry and flush layouts force captions off automatically.

`layout="tiles"` is kept as a legacy alias and now renders as `masonry`.

## Lightbox

Clicking a gallery image opens the built-in lightbox:

- Full-screen dark overlay with the full-size Flickr image centered.
- Bottom bar shows EXIF metadata: camera, lens, aperture, shutter speed, ISO, focal length, and location (when available).
- A "View on Flickr" link in the header opens the original photo page.
- Use left/right arrow keys or the on-screen buttons to navigate through gallery images.
- Press Escape or click outside the image to close.

## Notes

- If you only need public content, you can skip OAuth and just use a valid Flickr API key and secret.
- The `Logs` admin screen keeps a rolling in-database history of recent plugin events so debugging does not depend on server log access.
- EXIF data is cached per photo in both browser memory and WordPress transients.
- Gallery payloads and EXIF data are cached for 24 hours by default (minimum 1 hour) to conserve the Flickr API rate limit of 3 600 requests per hour.

## Gutenberg Block

The plugin also registers a `Flickr Portfolio` block in the Gutenberg editor.

Use it when you want a visual editor UI instead of typing shortcode manually. The block supports:

- photoset or photostream source selection
- wide and full-width alignment controls
- layout and column controls
- text-below-image toggle
- privacy filter and initial paging controls
