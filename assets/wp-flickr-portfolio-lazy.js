( function() {
	function parseSourceConfig( element ) {
		if ( ! element || ! element.dataset || ! element.dataset.wpfpSourceConfig ) {
			return null;
		}

		try {
			return JSON.parse( element.dataset.wpfpSourceConfig );
		} catch ( error ) {
			return null;
		}
	}

	function buildStatusUrl( source, expectedCount ) {
		var config = window.WPFlickrPortfolioLazy || {};
		var baseUrl = config.statusUrl || '';
		if ( ! baseUrl || ! source ) {
			return '';
		}

		var url = new URL( baseUrl, window.location.origin );
		url.searchParams.set( 'action', 'wpfp_lazy_status' );
		url.searchParams.set( 'source', source.source || 'photoset' );
		url.searchParams.set( 'user_id', source.user_id || '' );
		url.searchParams.set( 'photoset_id', source.photoset_id || '' );
		url.searchParams.set( 'per_page', String( source.per_page || 24 ) );
		url.searchParams.set( 'page', String( source.page || 1 ) );
		url.searchParams.set( 'privacy_filter', String( source.privacy_filter || 1 ) );
		url.searchParams.set( 'expected_count', String( expectedCount || 0 ) );

		return url.toString();
	}

	function cssEscape( value ) {
		if ( window.CSS && typeof window.CSS.escape === 'function' ) {
			return window.CSS.escape( value );
		}

		return String( value ).replace( /["\\]/g, '\\$&' );
	}

	function replaceWithLocalAttachment( gallery, photoId, item ) {
		var link = gallery.querySelector( 'a[data-flickr-id="' + cssEscape( photoId ) + '"]' );
		if ( ! link ) {
			return false;
		}

		var image = link.querySelector( 'img' );
		if ( ! image ) {
			return false;
		}

		if ( item.full_url ) {
			link.setAttribute( 'href', item.full_url );
		}

		if ( item.attachment_id ) {
			link.setAttribute( 'data-attachment-id', String( item.attachment_id ) );
		}

		link.setAttribute( 'data-wpfp-local', '1' );

		if ( item.image_url ) {
			image.setAttribute( 'src', item.image_url );
		}

		if ( item.srcset ) {
			image.setAttribute( 'srcset', item.srcset );
		} else {
			image.removeAttribute( 'srcset' );
		}

		if ( item.sizes ) {
			image.setAttribute( 'sizes', item.sizes );
		} else {
			image.removeAttribute( 'sizes' );
		}

		var figure = link.closest( '.wpfp-gallery-item' );
		if ( figure ) {
			figure.classList.add( 'wpfp-gallery-item-localized' );
		}

		return true;
	}

	function syncGallery( gallery ) {
		var source = parseSourceConfig( gallery );
		var expectedCount = parseInt( gallery.dataset.wpfpExpectedCount || '0', 10 ) || 0;
		var statusUrl = buildStatusUrl( source, expectedCount );
		var pollInterval = ( window.WPFlickrPortfolioLazy && window.WPFlickrPortfolioLazy.pollInterval ) || 2500;

		if ( ! statusUrl ) {
			return;
		}

		var stopped = false;

		function poll() {
			if ( stopped ) {
				return;
			}

			window.fetch( statusUrl, { credentials: 'same-origin' } )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						throw new Error( 'Invalid lazy sync status response.' );
					}

					var items = payload.data.items || {};
					Object.keys( items ).forEach( function( photoId ) {
						replaceWithLocalAttachment( gallery, photoId, items[ photoId ] || {} );
					} );

					if ( payload.data.complete || ! payload.data.active ) {
						gallery.dataset.wpfpLazyState = payload.data.complete ? 'complete' : 'idle';
						gallery.classList.add( 'wpfp-lazy-finished' );
						stopped = true;
						return;
					}

					window.setTimeout( poll, pollInterval );
				} )
				.catch( function() {
					window.setTimeout( poll, pollInterval * 2 );
				} );
		}

		poll();
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		var galleries = document.querySelectorAll( '.wpfp-gallery[data-wpfp-mode="lazy"][data-wpfp-lazy-state="pending"]' );
		Array.prototype.forEach.call( galleries, syncGallery );
	} );
}() );
