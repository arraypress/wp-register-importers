/**
 * Import screens.
 *
 * Upload a file, line its columns up, then walk it a batch at a time. The
 * walking is the only part that is not obvious: an import is one request per
 * batch, each starting where the last one said it stopped, and the loop has
 * to survive a batch that fails without either stopping silently or spinning.
 *
 * @package ArrayPress\RegisterImporters
 */

( function () {
	'use strict';

	document.querySelectorAll( '[data-importer][data-operation]' ).forEach( setup );

	/**
	 * Wire up one operation's box.
	 *
	 * @param {HTMLElement} box The box.
	 */
	function setup( box ) {
		var rest = box.dataset.rest.replace( /\/?$/, '/' );
		var file = null;

		var region = function ( name ) {
			return box.querySelector( '[data-region="' + name + '"]' );
		};

		var step = function ( name, shown ) {
			var section = box.querySelector( '[data-step="' + name + '"]' );

			if ( section ) {
				section.hidden = ! shown;
			}
		};

		var busy = function ( on ) {
			box.querySelectorAll( 'button[data-action]' ).forEach( function ( button ) {
				button.disabled = on;
			} );
		};

		/**
		 * Ask the endpoints something.
		 *
		 * @param {string} path   What to ask.
		 * @param {Object} params Query arguments.
		 * @param {Object} init   Anything else fetch needs.
		 *
		 * @return {Promise<Object>} What it said.
		 */
		function ask( path, params, init ) {
			var url = new URL( rest + path, window.location.origin );

			Object.keys( params || {} ).forEach( function ( key ) {
				url.searchParams.set( key, params[ key ] );
			} );

			return fetch( url, Object.assign( {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': box.dataset.nonce }
			}, init || {} ) ).then( function ( response ) {
				return response.json().then( function ( body ) {
					if ( ! response.ok ) {
						// A refusal carries its reason. A failure that does
						// not is still better named than "something went
						// wrong".
						throw new Error( body && body.message ? body.message : response.statusText );
					}

					return body;
				} );
			} );
		}

		/**
		 * Say something went wrong, where it can be read.
		 *
		 * @param {Error} error What went wrong.
		 */
		function complain( error ) {
			var notice = document.createElement( 'div' );

			notice.className = 'notice notice-error inline';
			notice.setAttribute( 'role', 'alert' );
			notice.appendChild( document.createElement( 'p' ) ).textContent = error.message;

			region( 'summary' ).replaceChildren( notice );
			step( 'report', true );
		}

		var actions = {
			/**
			 * Hand back a file showing what the importer expects.
			 */
			sample: function () {
				return ask( 'sample', { page: box.dataset.importer, operation: box.dataset.operation } )
					.then( function ( body ) {
						var link = document.createElement( 'a' );

						link.href = URL.createObjectURL( new Blob( [ body.contents ], { type: 'text/csv' } ) );
						link.download = body.filename;
						link.click();

						URL.revokeObjectURL( link.href );
					} );
			},

			/**
			 * Send the chosen file.
			 */
			upload: function () {
				var input = box.querySelector( '[data-input="file"]' );

				if ( ! input.files.length ) {
					throw new Error( input.dataset.empty || 'Choose a file first.' );
				}

				var body = new FormData();

				body.append( 'import_file', input.files[ 0 ] );

				return ask( 'upload', { page: box.dataset.importer }, { method: 'POST', body: body } )
					.then( function ( uploaded ) {
						file = uploaded;

						drawMapping( uploaded.headers );

						step( 'map', true );
					} );
			},

			/**
			 * Read the whole file and report, without changing anything.
			 */
			check: function () {
				return walk( false );
			},

			/**
			 * Do it.
			 */
			import: function () {
				return walk( true );
			}
		};

		box.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-action]' );

			if ( ! button || ! actions[ button.dataset.action ] ) {
				return;
			}

			event.preventDefault();
			busy( true );

			Promise.resolve()
				.then( actions[ button.dataset.action ] )
				.catch( complain )
				.finally( function () {
					busy( false );
				} );
		} );

		/**
		 * Build the column matching table.
		 *
		 * Each of the importer's columns gets a select holding the file's own
		 * headers, and one whose name matches is chosen already — which is
		 * most of them, most of the time.
		 *
		 * @param {string[]} headers The file's columns.
		 */
		function drawMapping( headers ) {
			var rows = document.createDocumentFragment();

			box.querySelectorAll( 'table.widefat tbody tr' ).forEach( function ( source ) {
				var key = source.querySelector( 'code' ).textContent;
				var label = source.querySelector( 'strong' ).textContent;
				var required = source.lastElementChild.textContent.trim();

				var row = document.createElement( 'tr' );
				var head = document.createElement( 'th' );
				var cell = document.createElement( 'td' );
				var select = document.createElement( 'select' );

				select.dataset.field = key;
				select.id = 'map-' + box.id + '-' + key;

				var none = document.createElement( 'option' );

				none.value = '';
				none.textContent = '—';
				select.appendChild( none );

				headers.forEach( function ( header ) {
					var option = document.createElement( 'option' );

					option.value = header;
					option.textContent = header;
					option.selected = header.toLowerCase() === key.toLowerCase()
						|| header.toLowerCase() === label.toLowerCase();

					select.appendChild( option );
				} );

				head.scope = 'row';

				var tag = document.createElement( 'label' );

				tag.htmlFor = select.id;
				tag.textContent = label;
				head.appendChild( tag );

				cell.appendChild( select );

				var note = document.createElement( 'p' );

				note.className = 'description';
				note.textContent = required;
				cell.appendChild( note );

				row.append( head, cell );
				rows.appendChild( row );
			} );

			region( 'mapping' ).replaceChildren( rows );
		}

		/**
		 * What the selects say.
		 *
		 * @return {Object} Field key to column name.
		 */
		function mapping() {
			var chosen = {};

			box.querySelectorAll( '[data-region="mapping"] select' ).forEach( function ( select ) {
				if ( select.value ) {
					chosen[ select.dataset.field ] = select.value;
				}
			} );

			return chosen;
		}

		/**
		 * Walk the file, a batch at a time.
		 *
		 * @param {boolean} commit Whether to import or only report.
		 *
		 * @return {Promise} When it has finished.
		 */
		function walk( commit ) {
			var offset = 0;

			step( 'report', true );
			region( 'summary' ).replaceChildren();
			region( 'errors' ).replaceChildren();

			var body = {
				page: box.dataset.importer,
				operation: box.dataset.operation,
				uuid: file.uuid,
				mapping: mapping(),
				commit: commit
			};

			function next() {
				return ask( 'run', {}, {
					method: 'POST',
					headers: {
						'X-WP-Nonce': box.dataset.nonce,
						'Content-Type': 'application/json'
					},
					body: JSON.stringify( Object.assign( {}, body, { offset: offset } ) )
				} ).then( function ( result ) {
					draw( result.progress, commit );

					// The server says where it got to. Trusting the client's
					// own count instead is how a batch that returned fewer
					// rows than it was asked for turns into a loop.
					if ( result.finished || result.offset <= offset ) {
						return result;
					}

					offset = result.offset;

					return next();
				} );
			}

			return next();
		}

		/**
		 * Show where the run has got to.
		 *
		 * @param {Object}  state  What has happened.
		 * @param {boolean} commit Whether this was an import.
		 */
		function draw( state, commit ) {
			var bar = region( 'bar' );

			bar.max = state.total || 1;
			bar.value = state.done;
			bar.className = 'importers-progress';

			region( 'count' ).textContent = state.done + ' / ' + state.total;

			var rows = [
				[ 'Created', state.created ],
				[ 'Updated', state.updated ],
				[ 'Skipped', state.skipped ],
				[ 'Failed', state.failed ]
			];

			var summary = document.createElement( 'table' );

			summary.className = 'widefat striped importers-summary';

			rows.forEach( function ( pair ) {
				if ( ! commit && pair[ 0 ] !== 'Failed' && pair[ 0 ] !== 'Skipped' ) {
					return;
				}

				var row = summary.insertRow();

				row.insertCell().textContent = pair[ 0 ];
				row.insertCell().textContent = pair[ 1 ];
			} );

			region( 'summary' ).replaceChildren( summary );

			drawErrors( state );
		}

		/**
		 * List what went wrong, by row.
		 *
		 * @param {Object} state What has happened.
		 */
		function drawErrors( state ) {
			var keys = Object.keys( state.errors || {} );

			if ( ! keys.length ) {
				region( 'errors' ).replaceChildren();

				return;
			}

			var table = document.createElement( 'table' );

			table.className = 'widefat striped';

			keys.forEach( function ( row ) {
				var line = table.insertRow();

				line.insertCell().textContent = 'Row ' + row;
				line.insertCell().textContent = state.errors[ row ];
			} );

			var wrapper = document.createElement( 'div' );

			wrapper.className = 'importers-errors';
			wrapper.appendChild( table );

			var children = [ wrapper ];

			if ( state.truncated ) {
				var more = document.createElement( 'p' );

				more.className = 'description';
				more.textContent = state.truncated + ' more rows had problems that are not listed.';
				children.push( more );
			}

			region( 'errors' ).replaceChildren.apply( region( 'errors' ), children );
		}
	}
}() );
