/**
 * Lango7 sidebar in the block editor (its own button at the top right). Plain wp.element, no build step.
 */
( function ( wp, cfg ) {
	if ( ! wp || ! cfg || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var C = wp.components;
	var Sidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar );
	var MenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem );
	var t = cfg.i18n;
	if ( ! Sidebar ) {
		return;
	}

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return s.replace( /%(\d+\$)?[sd]/g, function ( m, pos ) {
			return String( pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ] );
		} );
	}

	function link( href, text, cls ) {
		return el( 'a', { href: href, target: '_blank', rel: 'noopener noreferrer', className: cls },
			text, el( 'span', { className: 'screen-reader-text' }, ' ' + t.newTab ), el( 'span', { 'aria-hidden': 'true', className: 'lango7-ep-ext' }, ' ↗' ) );
	}

	function Row( props ) {
		var l = props.lang;
		var _o = useState( false ), open = _o[ 0 ], setOpen = _o[ 1 ];
		var _g = useState( l.engine ), engine = _g[ 0 ], setEngine = _g[ 1 ];
		var _w = useState( false ), working = _w[ 0 ], setWorking = _w[ 1 ];
		var _s = useState( '' ), note = _s[ 0 ], setNote = _s[ 1 ];
		var notes = [];
		( l.notes || [] ).forEach( function ( n, i ) {
			notes.push( el( 'span', { key: 'n' + i, className: 'lango7-ep-tag' + ( l.live ? '' : ' is-draft' ) }, n ) );
		} );
		var detail = [ fmt( t.progress, l.done, props.total ) ];
		if ( l.review ) {
			detail.push( fmt( t.review, l.review ) );
		}
		if ( l.unapproved ) {
			detail.push( fmt( t.unapproved, l.unapproved ) );
		}
		var state = 100 === l.percent ? 'is-done' : ( l.percent > 0 ? 'is-partial' : 'is-none' );
		var id = 'lango7-ep-' + l.code;

		// One call translates for up to 20 seconds; keep calling until done.
		function step( total ) {
			return wp.apiFetch( { path: cfg.path + props.postId + '/translate', method: 'POST', data: { lang: l.code, engine: engine } } ).then( function ( r ) {
				if ( r.open ) {
					window.open( r.open, '_blank', 'noopener' );
					setNote( t.opened );
					return;
				}
				total += r.translated || 0;
				if ( ! r.done ) {
					setNote( fmt( t.translating, total, total + r.remaining ) );
					return step( total );
				}
				setNote( r.failed ? fmt( t.someFailed, r.failed ) : ( total ? fmt( t.translated, total ) : t.nothing ) );
			} );
		}
		function translate() {
			setWorking( true );
			setNote( t.working );
			step( 0 ).catch( function ( e ) {
				setNote( ( e && e.message ) || t.error );
			} ).then( function () {
				setWorking( false );
				props.reload();
			} );
		}

		function remove() {
			if ( ! window.confirm( fmt( t.deleteAsk, l.name ) ) ) {
				return;
			}
			setWorking( true );
			setNote( t.deleting );
			wp.apiFetch( { path: cfg.path + props.postId + '/delete', method: 'POST', data: { lang: l.code } } ).then( function ( r ) {
				if ( ! r.deleted && r.shared ) {
					setNote( fmt( t.deletedNone, r.shared, ( r.others || [] ).join( ', ' ) || '…' ) );
					return;
				}
				setNote( fmt( t.deleted, r.deleted ) + ( r.shared ? ' ' + fmt( t.keptShared, r.shared ) : '' ) );
			}, function ( e ) {
				setNote( ( e && e.message ) || t.error );
			} ).then( function () {
				setWorking( false );
				props.reload();
			} );
		}

		return el( 'li', { className: 'lango7-ep-lang' + ( open ? ' is-open' : '' ) },
			el( 'button', { type: 'button', className: 'lango7-ep-head', 'aria-expanded': open, 'aria-controls': id, onClick: function () { setOpen( ! open ); } },
				el( 'span', { className: 'lango7-ep-caret', 'aria-hidden': 'true' }, open ? '▾' : '▸' ),
				el( 'strong', null, l.name ),
				notes,
				el( 'span', { className: 'lango7-ep-pct ' + state }, props.scanned ? l.percent + '%' : '' )
			),
			props.scanned ? el( 'div', { className: 'lango7-ep-bar', role: 'presentation' }, el( 'span', { className: state, style: { width: l.percent + '%' } } ) ) : null,
			open ? el( 'div', { id: id, className: 'lango7-ep-body' },
				props.scanned ? el( 'div', { className: 'lango7-ep-detail' }, detail.join( ' · ' ) ) : null,
				el( 'ol', { className: 'lango7-ep-steps' },
					el( 'li', null, l.url ? link( l.url, t.viewPage ) : el( 'span', { className: 'lango7-ep-off' }, t.viewPage + ' — ' + t.afterPublish ) ),
					el( 'li', null, l.editUrl ? link( l.editUrl, t.editOnPage ) : el( 'span', { className: 'lango7-ep-off' }, t.editOnPage + ' — ' + ( l.url ? t.noRights : t.afterPublish ) ) ),
					el( 'li', { className: 'lango7-ep-tr' },
						el( 'span', null, t.translatePage ),
						props.engines.length > 1 ? el( C.SelectControl, {
							label: t.service,
							hideLabelFromVision: true,
							value: engine,
							options: props.engines.map( function ( e ) { return { value: e.id, label: e.label }; } ),
							onChange: setEngine,
							disabled: working,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true
						} ) : null,
						el( C.Button, { variant: 'secondary', onClick: translate, disabled: working || props.dirty, isBusy: working }, t.translateBtn ),
						props.dirty ? el( 'p', { className: 'lango7-ep-note' }, t.saveFirst ) : null
					)
				),
				( l.actions || [] ).length ? el( 'div', { className: 'lango7-ep-actions' }, l.actions.map( function ( a, i ) {
					return el( C.Button, { key: i, variant: 'secondary', size: 'small', disabled: props.busy, onClick: function () { props.run( a ); } }, a.label );
				} ) ) : null,
				l.done > 0 ? el( C.Button, { variant: 'link', isDestructive: true, className: 'lango7-ep-delete', onClick: remove, disabled: working }, t.deleteLink ) : null,
				note ? el( 'p', { className: 'lango7-ep-message', role: 'status' }, note ) : null
			) : null
		);
	}

	function LangoPanel() {
		var post = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return {
				id: ed.getCurrentPostId(),
				saving: ed.isSavingPost() && ! ed.isAutosavingPost(),
				dirty: ed.isEditedPostDirty(),
				status: ed.getEditedPostAttribute( 'status' )
			};
		}, [] );
		var _d = useState( null ), data = _d[ 0 ], setData = _d[ 1 ];
		var _e = useState( '' ), error = _e[ 0 ], setError = _e[ 1 ];
		var _m = useState( '' ), message = _m[ 0 ], setMessage = _m[ 1 ];
		var _b = useState( false ), busy = _b[ 0 ], setBusy = _b[ 1 ];
		var wasSaving = useRef( false );
		var polls = useRef( 0 );

		function load() {
			if ( ! post.id ) {
				return Promise.resolve();
			}
			return wp.apiFetch( { path: cfg.path + post.id } ).then( function ( d ) {
				setData( d );
				setError( '' );
				return d;
			}, function ( e ) {
				setError( ( e && e.message ) || t.error );
			} );
		}

		useEffect( function () { load(); }, [ post.id ] );

		// Reload after each save: the published page is what gets translated.
		useEffect( function () {
			if ( wasSaving.current && ! post.saving ) {
				load();
			}
			wasSaving.current = post.saving;
		}, [ post.saving ] );

		// While a background translation runs, check every 10 seconds (up to 10 minutes).
		useEffect( function () {
			if ( ! data || ! data.status ) {
				polls.current = 0;
				return;
			}
			if ( polls.current > 60 ) {
				return;
			}
			var timer = setTimeout( function () {
				polls.current++;
				load();
			}, 10000 );
			return function () { clearTimeout( timer ); };
		}, [ data ] );

		function translate() {
			var tr = data.translate;
			if ( ! tr ) {
				return;
			}
			if ( ! tr.background ) {
				window.open( tr.url, '_blank', 'noopener' );
				setMessage( t.opened );
				return;
			}
			setBusy( true );
			setMessage( t.working );
			window.fetch( tr.url, { credentials: 'same-origin', redirect: 'manual' } ).then( function () {
				setMessage( t.started );
				return load();
			}, function () {
				setMessage( t.error );
			} ).then( function () { setBusy( false ); } );
		}

		function run( action ) {
			setBusy( true );
			wp.apiFetch( { path: action.path, method: action.method || 'POST', data: action.data || {} } ).then( function ( r ) {
				setMessage( ( r && r.message ) || '' );
				return load();
			}, function ( e ) {
				setMessage( ( e && e.message ) || t.error );
			} ).then( function () { setBusy( false ); } );
		}

		function refresh() {
			setBusy( true );
			setMessage( '' );
			load().then( function ( d ) {
				setBusy( false );
				if ( d ) {
					setMessage( t.updated );
				}
			} );
		}

		var body;
		if ( error ) {
			body = el( 'p', { className: 'lango7-ep-note' }, error );
		} else if ( ! data ) {
			body = el( 'p', { className: 'lango7-ep-note' }, t.loading );
		} else if ( 'unpublished' === data.state ) {
			body = el( 'p', { className: 'lango7-ep-note' }, t.unpublished );
		} else if ( 'nopath' === data.state ) {
			body = el( 'p', { className: 'lango7-ep-note' }, t.noPath );
		} else if ( 'excluded' === data.state ) {
			body = el( 'p', { className: 'lango7-ep-note' }, t.excluded );
		} else if ( 'blocked' === data.state ) {
			body = el( 'p', { className: 'lango7-ep-note' }, t.blocked );
		} else {
			var scanned = 'notscanned' !== data.state;
			var draft = 'draft' === data.state;
			body = el( wp.element.Fragment, null,
				draft ? el( 'p', { className: 'lango7-ep-note' }, t.draft ) : null,
				scanned ? null : el( 'p', { className: 'lango7-ep-note' }, t.notScanned ),
				el( 'p', { className: 'lango7-ep-hint' }, t.pick ),
				el( 'ul', { className: 'lango7-ep-list' }, data.languages.map( function ( l ) {
					return el( Row, { key: l.code, lang: l, total: data.total, scanned: scanned, busy: busy, run: run, postId: post.id, engines: data.engines || [], dirty: post.dirty, reload: load } );
				} ) ),
				data.status
					? el( 'p', { className: 'lango7-ep-status' }, el( C.Spinner ), data.status )
					: ( data.complete
						? el( 'p', { className: 'lango7-ep-note is-done' }, t.complete )
						: ( data.translate && ! draft ? el( 'div', { className: 'lango7-ep-translate' },
							el( C.Button, { variant: 'primary', onClick: translate, disabled: busy || post.dirty, isBusy: busy }, t.translate ),
							post.dirty ? el( 'p', { className: 'lango7-ep-note' }, t.saveFirst ) : null
						) : null ) ),
				message ? el( 'p', { className: 'lango7-ep-message', role: 'status' }, message ) : null,
				el( C.Button, { variant: 'link', className: 'lango7-ep-refresh', onClick: refresh, disabled: busy, isBusy: busy }, t.refresh )
			);
		}
		function block( off ) {
			setBusy( true );
			setMessage( '' );
			wp.apiFetch( { path: cfg.path + post.id + '/no-translate', method: 'POST', data: { off: off } } ).then( function ( d ) {
				setData( d );
			}, function ( e ) {
				setMessage( ( e && e.message ) || t.error );
			} ).then( function () { setBusy( false ); } );
		}
		var toggle = data && data.canBlock && 'nopath' !== data.state && 'excluded' !== data.state
			? el( 'div', { className: 'lango7-ep-block' }, el( C.ToggleControl, {
				label: t.noTranslate,
				help: t.noTranslateHelp,
				checked: !! data.blocked,
				disabled: busy,
				onChange: block,
				__nextHasNoMarginBottom: true
			} ) )
			: null;

		return el( wp.element.Fragment, null,
			MenuItem ? el( MenuItem, { target: 'lango7-sidebar', icon: 'translation' }, t.title ) : null,
			el( Sidebar, { name: 'lango7-sidebar', title: t.title, icon: 'translation', className: 'lango7-ep' },
				el( C.PanelBody, { opened: true }, body, toggle )
			)
		);
	}

	wp.plugins.registerPlugin( 'lango7', { render: LangoPanel, icon: 'translation' } );
}( window.wp, window.lango7Panel ) );
