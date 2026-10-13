/**
 * Maintenance tools under Settings > Media, and the sizes panel on a single image.
 *
 * Every task runs as a series of small requests, so a library of thousands of
 * images never hits a PHP time limit.
 */
( () => {
	const config = window.yourmarkImageSizes;

	if ( ! config ) {
		return;
	}

	const text = ( key, values = {} ) =>
		config.i18n[ key ].replace( /\{(\w+)\}/g, ( match, name ) => values[ name ] ?? match );

	// Sentences about one file have their own wording, under the key with a 1 after it.
	const textFor = ( key, count, values = {} ) => text( count === 1 && config.i18n[ `${ key }1` ] ? `${ key }1` : key, values );

	const megabytes = ( bytes ) =>
		`${ ( bytes / 1048576 ).toLocaleString( document.documentElement.lang || undefined, { maximumFractionDigits: 1 } ) }\u00a0MB`;

	const request = async ( endpoint, data = {} ) => {
		const body = new FormData();

		body.append( 'action', `yourmark_image_sizes_${ endpoint }` );
		body.append( '_ajax_nonce', config.nonce );

		for ( const [ key, value ] of Object.entries( data ) ) {
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => body.append( `${ key }[]`, item ) );
			} else if ( value !== undefined ) {
				body.append( key, value );
			}
		}

		const response = await fetch( config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
		const json = await response.json().catch( () => null );

		if ( ! json?.success ) {
			throw new Error( json?.data?.message ?? response.statusText );
		}

		return json.data;
	};

	// The server answers each batch with the last ID it handled, which is where the next one starts.
	const eachBatch = async ( operation, onBatch ) => {
		let after = 0;
		let done = 0;
		let result;

		do {
			result = await request( 'batch', { operation, after } );
			after = result.last;
			done += result.processed;
			onBatch( result, done );
		} while ( ! result.done );
	};

	const tally = async ( operation, message, ui ) => {
		let files = 0;
		let bytes = 0;

		await eachBatch( operation, ( result, done ) => {
			files += result.files;
			bytes += result.bytes;
			ui.progress( text( message, { done, total: result.total } ), done / result.total );
		} );

		return { files, size: megabytes( bytes ) };
	};

	// Two buttons in place of the tool's own, resolving to whether the user went ahead.
	const askToDelete = ( ui, found ) =>
		new Promise( ( resolve ) => {
			const actions = ui.tool.querySelector( '.yourmark-image-sizes__actions' );
			const original = [ ...actions.children ];
			const choice = ( label, className, value ) => {
				const button = document.createElement( 'button' );

				button.type = 'button';
				button.className = className;
				button.textContent = label;
				button.addEventListener( 'click', () => {
					actions.replaceChildren( ...original );
					resolve( value );
				} );

				return button;
			};

			const confirm = choice( textFor( 'delete', found.files, found ), 'button is-destructive', true );

			actions.replaceChildren( confirm, choice( text( 'cancel' ), 'button', false ) );
			ui.setBusy( false );
			confirm.focus();
		} );

	// Each task resolves to [ message, tone ]; a tone of 'success' or 'error' styles the message.
	const tasks = {
		async warm( ui ) {
			const { urls } = await request( 'pages' );
			let made = 0;

			if ( ! urls.length ) {
				return [ text( 'noPages' ), 'error' ];
			}

			for ( let i = 0; i < urls.length; i += 3 ) {
				ui.progress( text( 'warming', { done: i, total: urls.length } ), i / urls.length );
				made += ( await request( 'warm', { urls: urls.slice( i, i + 3 ) } ) ).made;
			}

			return [ text( made ? 'warmed' : 'warmedNone', { made, total: urls.length } ), 'success' ];
		},

		async convert( ui ) {
			const counts = { converted: 0, current: 0, icon: 0, animated: 0, type: 0, failed: 0 };

			await eachBatch( 'convert', ( result, done ) => {
				Object.keys( counts ).forEach( ( key ) => ( counts[ key ] += result[ key ] ) );
				ui.progress( text( 'converting', { done, total: result.total } ), done / result.total );
			} );

			const format = config.format;
			const parts = [ text( counts.converted ? 'converted' : 'convertedNone', { converted: counts.converted, format } ) ];

			if ( counts.current ) {
				parts.push( text( 'skipCurrent', { count: counts.current, format } ) );
			}

			if ( counts.icon ) {
				parts.push( text( 'skipIcon' ) );
			}

			if ( counts.animated ) {
				parts.push( text( 'skipAnimated', { count: counts.animated } ) );
			}

			if ( counts.type ) {
				parts.push( text( 'skipType', { count: counts.type } ) );
			}

			if ( counts.failed ) {
				parts.push( text( 'failed', { count: counts.failed } ) );
			}

			return [ parts.join( ' ' ), counts.failed ? 'error' : 'success' ];
		},

		async prune( ui ) {
			const found = await tally( 'count', 'counting', ui );

			if ( ! found.files ) {
				return [ text( 'nothing' ), 'success' ];
			}

			ui.progress( textFor( 'found', found.files, found ) );

			if ( ! ( await askToDelete( ui, found ) ) ) {
				return [ text( 'cancelled' ) ];
			}

			ui.setBusy( true );

			const pruned = await tally( 'prune', 'pruning', ui );

			return [ textFor( 'pruned', pruned.files, pruned ), 'success' ];
		},

		// One image: every missing size, or with data-size only that one.
		async image( ui, button ) {
			ui.progress( text( 'making' ) );

			const size = button.dataset.size;
			const result = await request( 'image', { id: button.dataset.id, size } );
			const body = ui.tool.querySelector( '.yourmark-image-sizes__body' );

			if ( body ) {
				body.innerHTML = result.panel;
			} else if ( ! result.action ) {
				// The list view's link: gone once there is nothing left to do.
				button.remove();
			}

			if ( size ) {
				return [ text( 'madeOne' ), 'success' ];
			}

			return [ text( result.converted ? 'convertedOne' : 'made', { made: result.made } ), 'success' ];
		},
	};

	tasks.size = tasks.image;

	// Delegated, because the media modal renders its fields after the page has loaded.
	document.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '[data-yourmark-image-sizes]' );

		if ( ! button || button.disabled ) {
			return;
		}

		event.preventDefault();

		const tool = button.closest( '[data-yourmark-image-sizes-tool]' );
		const group = button.closest( '[data-yourmark-image-sizes-group]' ) ?? tool;
		const status = tool.querySelector( '.yourmark-image-sizes__status' );
		const bar = tool.querySelector( 'progress' );

		const ui = {
			tool,
			progress( message, share ) {
				status.className = 'yourmark-image-sizes__status';
				status.textContent = message;

				if ( bar ) {
					bar.hidden = share === undefined;
					bar.value = Math.round( ( share ?? 0 ) * 100 );
				}
			},
			finish( message, tone ) {
				status.className = `yourmark-image-sizes__status${ tone ? ` is-${ tone }` : '' }`;
				status.textContent = message;

				if ( bar ) {
					bar.hidden = true;
				}
			},
			// One task at a time across all tools: they share the server's resize slots.
			setBusy( busy ) {
				group.querySelectorAll( '[data-yourmark-image-sizes]' ).forEach( ( item ) => ( item.disabled = busy ) );

				if ( busy ) {
					tool.setAttribute( 'aria-busy', 'true' );
				} else {
					tool.removeAttribute( 'aria-busy' );
				}
			},
		};

		ui.setBusy( true );

		try {
			ui.finish( ...( await tasks[ button.dataset.yourmarkImageSizes ]( ui, button ) ) );
		} catch ( error ) {
			ui.finish( text( 'error', { message: error.message } ), 'error' );
		} finally {
			ui.setBusy( false );

			// The panel was redrawn and the clicked button may be gone; keep keyboard users where the result is.
			if ( ! document.contains( button ) && status.hasAttribute( 'tabindex' ) ) {
				status.focus();
			}
		}
	} );
} )();
