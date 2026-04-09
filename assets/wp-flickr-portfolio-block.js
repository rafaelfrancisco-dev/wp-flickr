( function( blocks, blockEditor, components, element, i18n, serverSideRender ) {
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var ServerSideRender = serverSideRender && serverSideRender.default ? serverSideRender.default : serverSideRender;
	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;

	registerBlockType( 'wp-flickr-portfolio/gallery', {
		apiVersion: 2,
		title: __( 'Flickr Portfolio', 'wp-flickr-portfolio' ),
		description: __( 'Display a Flickr album or photostream without typing shortcode manually.', 'wp-flickr-portfolio' ),
		icon: 'format-gallery',
		category: 'widgets',
		attributes: {
			source: { type: 'string', default: 'photoset' },
			user_id: { type: 'string', default: '' },
			photoset_id: { type: 'string', default: '' },
			per_page: { type: 'number', default: 24 },
			page: { type: 'number', default: 1 },
			privacy_filter: { type: 'number', default: 1 },
			layout: { type: 'string', default: 'grid' },
			columns: { type: 'number', default: 3 },
			captions: { type: 'boolean', default: true },
			title: { type: 'string', default: '' },
			class: { type: 'string', default: '' }
		},
		supports: {
			align: [ 'wide', 'full' ],
			html: false
		},
		edit: function( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();
			var normalizedLayout = attributes.layout === 'tiles' ? 'masonry' : attributes.layout;

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{
							title: __( 'Gallery Settings', 'wp-flickr-portfolio' ),
							initialOpen: true
						},
						createElement( SelectControl, {
							label: __( 'Source', 'wp-flickr-portfolio' ),
							value: attributes.source,
							options: [
								{ label: __( 'Photoset / Album', 'wp-flickr-portfolio' ), value: 'photoset' },
								{ label: __( 'Photostream', 'wp-flickr-portfolio' ), value: 'photostream' }
							],
							onChange: function( value ) {
								setAttributes( { source: value } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Flickr User ID (NSID)', 'wp-flickr-portfolio' ),
							value: attributes.user_id,
							help: __( 'Leave empty to use the connected Flickr account when available.', 'wp-flickr-portfolio' ),
							onChange: function( value ) {
								setAttributes( { user_id: value } );
							}
						} ),
						attributes.source === 'photoset'
							? createElement( TextControl, {
								label: __( 'Photoset ID', 'wp-flickr-portfolio' ),
								value: attributes.photoset_id,
								onChange: function( value ) {
									setAttributes( { photoset_id: value } );
								}
							} )
							: null,
						createElement( SelectControl, {
							label: __( 'Privacy Filter', 'wp-flickr-portfolio' ),
							value: String( attributes.privacy_filter ),
							options: [
								{ label: __( 'Public', 'wp-flickr-portfolio' ), value: '1' },
								{ label: __( 'Friends', 'wp-flickr-portfolio' ), value: '2' },
								{ label: __( 'Family', 'wp-flickr-portfolio' ), value: '3' },
								{ label: __( 'Friends & family', 'wp-flickr-portfolio' ), value: '4' },
								{ label: __( 'Private', 'wp-flickr-portfolio' ), value: '5' }
							],
							onChange: function( value ) {
								setAttributes( { privacy_filter: parseInt( value, 10 ) || 1 } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Custom Title', 'wp-flickr-portfolio' ),
							value: attributes.title,
							onChange: function( value ) {
								setAttributes( { title: value } );
							}
						} )
					),
					createElement(
						PanelBody,
						{
							title: __( 'Layout', 'wp-flickr-portfolio' ),
							initialOpen: false
						},
						createElement( SelectControl, {
							label: __( 'Layout', 'wp-flickr-portfolio' ),
							value: normalizedLayout,
							options: [
								{ label: __( 'Grid', 'wp-flickr-portfolio' ), value: 'grid' },
								{ label: __( 'Masonry', 'wp-flickr-portfolio' ), value: 'masonry' }
							],
							onChange: function( value ) {
								setAttributes( {
									layout: value,
									captions: value === 'masonry' ? false : attributes.captions
								} );
							}
						} ),
						createElement( RangeControl, {
							label: __( 'Columns', 'wp-flickr-portfolio' ),
							value: attributes.columns,
							onChange: function( value ) {
								setAttributes( { columns: value || 3 } );
							},
							min: 1,
							max: 6
						} ),
						createElement( ToggleControl, {
							label: __( 'Show text below images', 'wp-flickr-portfolio' ),
							checked: !! attributes.captions,
							disabled: normalizedLayout === 'masonry',
							help: normalizedLayout === 'masonry'
								? __( 'Masonry forces captions off so the image grid can pack tightly.', 'wp-flickr-portfolio' )
								: __( 'Shows the Flickr title and description below each image.', 'wp-flickr-portfolio' ),
							onChange: function( value ) {
								setAttributes( { captions: value } );
							}
						} )
					),
					createElement(
						PanelBody,
						{
							title: __( 'Query', 'wp-flickr-portfolio' ),
							initialOpen: false
						},
						createElement( RangeControl, {
							label: __( 'Items per page', 'wp-flickr-portfolio' ),
							value: attributes.per_page,
							onChange: function( value ) {
								setAttributes( { per_page: value || 1 } );
							},
							min: 1,
							max: 100
						} ),
						createElement( RangeControl, {
							label: __( 'Page', 'wp-flickr-portfolio' ),
							value: attributes.page,
							onChange: function( value ) {
								setAttributes( { page: value || 1 } );
							},
							min: 1,
							max: 50
						} ),
						createElement( TextControl, {
							label: __( 'Extra CSS classes', 'wp-flickr-portfolio' ),
							value: attributes.class,
							onChange: function( value ) {
								setAttributes( { class: value } );
							}
						} )
					)
				),
				createElement(
					'div',
					blockProps,
					createElement( ServerSideRender, {
						block: 'wp-flickr-portfolio/gallery',
						attributes: attributes
					} )
				)
			);
		},
		save: function() {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.element,
	window.wp.i18n,
	window.wp.serverSideRender
);
