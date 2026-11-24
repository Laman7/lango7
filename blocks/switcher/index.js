/* Language Switcher block (no build step): settings in the sidebar, preview rendered by the server. */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'lango7/switcher', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					{},
					el(
						c.PanelBody,
						{ title: __( 'Switcher', 'lango7' ) },
						el( c.SelectControl, {
							label: __( 'Style', 'lango7' ),
							value: a.style,
							options: [
								{ value: 'list', label: __( 'List of links', 'lango7' ) },
								{ value: 'dropdown', label: __( 'Dropdown', 'lango7' ) },
								{ value: 'globe', label: __( 'Globe icon', 'lango7' ) }
							],
							onChange: function ( v ) {
								props.setAttributes( { style: v } );
							},
							__nextHasNoMarginBottom: true
						} ),
						el( c.SelectControl, {
							label: __( 'Show languages as', 'lango7' ),
							value: a.names,
							options: [
								{ value: 'native', label: __( 'Native name (Français)', 'lango7' ) },
								{ value: 'name', label: __( 'English name (French)', 'lango7' ) },
								{ value: 'code', label: __( 'Code (FR)', 'lango7' ) }
							],
							onChange: function ( v ) {
								props.setAttributes( { names: v } );
							},
							__nextHasNoMarginBottom: true
						} )
					)
				),
				el( c.Disabled, {}, el( ServerSideRender, {
					block: 'lango7/switcher',
					attributes: { style: a.style, names: a.names },
					EmptyResponsePlaceholder: function () {
						return el( c.Placeholder, {
							icon: 'translation',
							label: __( 'Language Switcher', 'lango7' ),
							instructions: __( 'Shows once a language is added in Translate → Settings.', 'lango7' )
						} );
					}
				} ) )
			);
		}
	} );
}( window.wp ) );
