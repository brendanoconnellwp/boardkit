/**
 * BoardKit board screen (Phase 2: read-only).
 *
 * Data comes from boardkit/v1 over wp.apiFetch (which adds the REST nonce). Every string from
 * the server is plain text and goes into the DOM through textContent / setAttribute — never
 * innerHTML — so a post title or lane label can't inject markup.
 */
( function () {
	'use strict';

	var config = window.BoardKitConfig || {};
	var i18n = config.i18n || {};
	var root = document.getElementById( 'bk-board' );
	var notice = document.getElementById( 'bk-notice' );
	if ( ! root || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var apiFetch = window.wp.apiFetch;
	var base = '/' + config.namespace + '/boards/' + encodeURIComponent( config.board );

	function speak( msg ) {
		if ( window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( msg );
		}
	}

	function sprintf( fmt ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var auto = 0;
		return String( fmt ).replace( /%(?:(\d+)\$)?([sd])/g, function ( m, pos ) {
			var v = args[ pos ? pos - 1 : auto++ ];
			return v === undefined ? '' : String( v );
		} );
	}

	/** el( 'div', { class: 'x', 'aria-label': '…' }, [ children | 'text' ] ) */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'text' ) {
				node.textContent = v;
			} else {
				node.setAttribute( k, v === true ? '' : String( v ) );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return node;
	}

	/** Only http(s) links from the server are used as hrefs. */
	function safeUrl( url ) {
		return /^https?:\/\//i.test( url || '' ) ? url : '';
	}

	function showError( msg ) {
		notice.textContent = '';
		notice.appendChild( el( 'div', { class: 'notice notice-error inline' }, [ el( 'p', { text: msg } ) ] ) );
		speak( msg );
	}

	function formatDate( ymd ) {
		var parts = ( ymd || '' ).split( '-' );
		if ( parts.length !== 3 ) {
			return ymd;
		}
		var d = new Date( Date.UTC( +parts[ 0 ], +parts[ 1 ] - 1, +parts[ 2 ] ) );
		try {
			return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' } );
		} catch ( e ) {
			return ymd;
		}
	}

	function avatar( person, cls ) {
		if ( ! person ) {
			return null;
		}
		return el( 'img', {
			class: 'bk-avatar ' + ( cls || '' ),
			src: safeUrl( person.avatar ) || null,
			alt: person.name,
			title: person.name,
			width: 24,
			height: 24,
			loading: 'lazy',
		} );
	}

	function renderCard( card ) {
		var editUrl = safeUrl( card.edit_url );
		var title = editUrl
			? el( 'a', { class: 'bk-card-title', href: editUrl, text: card.title } )
			: el( 'span', { class: 'bk-card-title', text: card.title } );

		var meta = el( 'div', { class: 'bk-card-meta' } );
		if ( card.status && card.fields.indexOf( 'status' ) !== -1 ) {
			meta.appendChild( el( 'span', { class: 'bk-chip bk-status bk-status-' + card.status.value, text: card.status.label } ) );
		}
		if ( card.due && card.due.date ) {
			var dueText = sprintf( i18n.due, formatDate( card.due.date ) );
			meta.appendChild( el( 'span', {
				class: 'bk-chip bk-due' + ( card.due.overdue ? ' is-overdue' : '' ),
				text: card.due.overdue ? dueText + ' · ' + i18n.overdue : dueText,
			} ) );
		}
		( card.terms || [] ).forEach( function ( t ) {
			meta.appendChild( el( 'span', { class: 'bk-chip bk-term', text: t } ) );
		} );

		var people = el( 'div', { class: 'bk-card-people' } );
		if ( card.author ) {
			people.appendChild( el( 'span', { class: 'screen-reader-text', text: i18n.author + ': ' + card.author.name } ) );
			people.appendChild( avatar( card.author, 'bk-author' ) );
		}
		if ( card.assignees && card.assignees.length ) {
			var names = card.assignees.map( function ( p ) { return p.name; } ).join( ', ' );
			people.appendChild( el( 'span', { class: 'screen-reader-text', text: i18n.assignees + ': ' + names } ) );
			card.assignees.forEach( function ( p ) {
				people.appendChild( avatar( p, 'bk-assignee' ) );
			} );
		}

		var thumb = card.thumbnail ? el( 'img', { class: 'bk-thumb', src: safeUrl( card.thumbnail ) || null, alt: '', loading: 'lazy' } ) : null;

		return el( 'li', {
			class: 'bk-card',
			role: 'listitem',
			tabindex: 0,
			'data-id': card.id,
			'data-modified': card.modified_gmt,
			'aria-label': card.title,
		}, [ thumb, title, meta.childNodes.length ? meta : null, people.childNodes.length ? people : null ] );
	}

	function laneHeaderText( lane, count ) {
		if ( lane.wip > 0 ) {
			return count > lane.wip ? sprintf( i18n.overWip, count, lane.wip ) : count + ' / ' + lane.wip;
		}
		return String( count );
	}

	function renderLane( lane ) {
		var listId = 'bk-lane-' + lane.value;
		var count = el( 'span', { class: 'bk-lane-count' + ( lane.wip > 0 && lane.count > lane.wip ? ' is-over' : '' ), text: laneHeaderText( lane, lane.count ) } );
		var toggle = el( 'button', {
			type: 'button',
			class: 'bk-lane-toggle',
			'aria-expanded': 'true',
			'aria-controls': listId,
		}, [ el( 'span', { class: 'bk-lane-label', text: lane.label } ), count ] );

		var list = el( 'ol', {
			id: listId,
			class: 'bk-cards',
			role: 'list',
			'aria-label': sprintf( i18n.laneLabel, lane.label, lane.count ),
		} );
		var more = el( 'button', { type: 'button', class: 'button bk-more', hidden: true, text: i18n.loadMore } );
		var section = el( 'section', {
			class: 'bk-lane' + ( lane.readonly ? ' is-readonly' : '' ),
			'data-lane': lane.value,
			style: lane.color ? '--bk-lane-color:' + lane.color : null,
		}, [ el( 'h2', { class: 'bk-lane-head' }, [ toggle ] ), list, more ] );

		toggle.addEventListener( 'click', function () {
			var open = toggle.getAttribute( 'aria-expanded' ) === 'true';
			toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			section.classList.toggle( 'is-collapsed', open );
		} );

		var next = 0;
		function load( announce ) {
			more.disabled = true;
			list.setAttribute( 'aria-busy', 'true' );
			return apiFetch( { path: base + '/lanes/' + encodeURIComponent( lane.value ) + '/cards?offset=' + next } )
				.then( function ( res ) {
					res.cards.forEach( function ( c ) {
						list.appendChild( renderCard( c ) );
					} );
					if ( ! list.children.length ) {
						list.appendChild( el( 'li', { class: 'bk-empty', text: i18n.empty } ) );
					}
					next = res.next_offset;
					more.hidden = next === null;
					more.disabled = false;
					list.removeAttribute( 'aria-busy' );
					if ( announce ) {
						speak( i18n.loadedMore );
					}
				} )
				.catch( function ( err ) {
					more.disabled = false;
					list.removeAttribute( 'aria-busy' );
					showError( ( err && err.message ) || i18n.error );
				} );
		}
		more.addEventListener( 'click', function () {
			load( true );
		} );

		return { node: section, load: load };
	}

	apiFetch( { path: base } )
		.then( function ( board ) {
			root.textContent = '';
			var lanes = board.lanes.map( renderLane );
			lanes.forEach( function ( l ) {
				root.appendChild( l.node );
			} );
			root.removeAttribute( 'aria-busy' );
			return Promise.all( lanes.map( function ( l ) {
				return l.load( false );
			} ) );
		} )
		.catch( function ( err ) {
			root.removeAttribute( 'aria-busy' );
			root.textContent = '';
			showError( ( err && err.message ) || i18n.error );
		} );
}() );
