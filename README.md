# WP Flickr Portfolio

WordPress plugin for pulling Flickr albums and photostream images into a portfolio site.

Development and architecture notes live in [DEVELOPMENT.md](./DEVELOPMENT.md).

## Features

- Connect a Flickr account with OAuth 1.0a for public and private read access.
- Render remote Flickr galleries with the `[flickr_portfolio]` shortcode.
- Insert galleries from a native Gutenberg block.
- Import Flickr images into the Media Library via scheduled sync with on-demand Run Now.
- Refresh metadata for already imported Flickr attachments when syncing.
- Keep selected Flickr sources in sync with optional recurring scheduled sync and an immediate admin-side `Run Now`.
- Inspect recent plugin activity from a dedicated admin Logs screen with pagination, filtering, and text-file export.
- Use remote galleries with Meow Lightbox via standard `.gallery` markup.
- Use imported attachments with Meow Gallery through the normal WordPress gallery flow.
- Offer two layout modes: uniform `grid` and aspect-ratio-preserving `masonry`.
- Support frontend pagination with gallery-specific page links.

## Installation

1. Copy this folder into `wp-content/plugins/wp-flickr-portfolio`.
2. Activate `WP Flickr Portfolio`.
3. Go to `WP Flickr Portfolio` in the WordPress admin sidebar.
4. Add your Flickr API key and secret from https://www.flickr.com/services/api/.
5. Configure your Flickr app's OAuth callback URL with the value shown on the plugin settings page.
6. Click `Connect Flickr` if you need private content access.
7. Use the plugin page to configure scheduled syncs or run syncs on demand as needed.
8. Open the `Logs` subpage when you need to inspect sync jobs, Flickr API retries, or gallery mode decisions.

## Shortcode

### Photoset

```text
[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001"]
```

### Photostream

```text
[flickr_portfolio source="photostream" user_id="12345678@N00" per_page="18"]
```

### Synced Attachments

```text
[flickr_portfolio source="photoset" user_id="12345678@N00" photoset_id="72157700000000001" mode="auto"]
```

`mode="remote"` renders Flickr-hosted images directly.

`mode="media"` uses imported attachments only. If that exact gallery page has not been synced yet, the plugin shows a local-only error instead of falling back.

`mode="auto"` renders imported attachments when available and falls back to remote Flickr URLs otherwise.

`page` sets the initial page to load. Visitors can move between pages from the frontend pagination links, which use gallery-specific query-string parameters.

`captions="false"` hides the text below each image. Masonry layout forces captions off automatically.

`layout="tiles"` is kept as a legacy alias and now renders as `masonry`.

## Notes

- Meow Lightbox should detect the remote galleries out of the box because this plugin renders the `.gallery` class that Meow watches by default.
- Meow Gallery works best with the synced attachment mode, because imported Flickr images become regular WordPress media items.
- If you only need public content, you can skip OAuth and just use a valid Flickr API key and secret.
- Scheduled sync uses WordPress cron for recurring runs. `Run Now` executes immediately in the admin request instead of relying on a background WP-Cron loopback.
- Flickr documents a shared API-key limit of `3600 queries per hour`, so uncached sync requests are paced conservatively.
- Scheduled sync prefers Flickr's full/original file when available, so the local full-size attachment keeps the original image bytes and any embedded EXIF that Flickr preserved in that file.
- Media imports now require the Flickr original by default. A new admin setting can re-enable fallback to smaller Flickr display sizes if you explicitly want that behavior.
- Attachment-backed modes are matched against the exact source configuration, including `source`, `user_id`, `photoset_id`, `per_page`, `page`, and `privacy_filter`.
- The `Logs` admin screen keeps a rolling in-database history of recent plugin events so debugging does not depend on server log access.
- Flickr imports skip intermediate WordPress size generation and disable WordPress big-image downscaling during sideload so the original file is preserved locally.

## Gutenberg Block

The plugin also registers a `Flickr Portfolio` block in the Gutenberg editor.

Use it when you want a visual editor UI instead of typing shortcode manually. The block supports:

- photoset or photostream source selection
- wide and full-width alignment controls
- remote, media, and auto rendering modes
- layout and column controls
- text-below-image toggle
- privacy filter and initial paging controls
