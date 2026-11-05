/**
 * BoardKit board screen.
 *
 * Data comes from boardkit/v1 over wp.apiFetch (which adds the REST nonce). Every string from
 * the server is plain text and goes into the DOM through textContent / setAttribute — never
 * innerHTML — so a post title or lane label can't inject markup.
 *
 * Moving a card has three equivalent inputs, all ending in moveCard() (spec §5.5):
 *   - drag and drop (SortableJS, desktop only);
 *   - keyboard: focus a card, Space to pick up, ←/→ to choose a lane, Space to drop, Esc to cancel;
 *   - the card's ⋯ menu → "Move to …".
 * moveCard() is optimistic: the card moves at once and is put back if the server refuses.
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
	var UNSORTED = '__unsorted';
	var mobile = window.matchMedia ? window.matchMedia( '(max-width: 782px)' ) : { matches: false, addEventListener: function () {} };

	/** lane value → { lane, section, list, countEl, more, count } in board order */
	var lanes = {};
	var laneOrder = [];
	/** card id → card data from the server */
	var cards = {};
	var sortables = [];
	/** Keyboard pick-up state: { id, target } or null */
	var picked = null;
	/** The site's timezone name, shown next to the schedule picker. */
	var timezone = '';
	/** Visible keyboard instructions while a card is picked up. */
	var hint = el( 'div', { class: 'bk-hint', hidden: true, 'aria-hidden': 'true' } );
	/** The open card panel: { id, root, body, tab, data, frame } or null. */
	var panel = null;
	var pi18n = i18n.panel || {};

	// ---- helpers ---------------------------------------------------------------------------

	function speak( msg ) {
		if ( window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( msg, 'assertive' );
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

	/** el( 'div', { class: 'x', text: '…' }, [ children ] ) — attributes and text only. */
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

	function showNotice( msg, type ) {
		notice.textContent = '';
		if ( msg ) {
			notice.appendChild( el( 'div', { class: 'notice inline notice-' + ( type || 'error' ) }, [ el( 'p', { text: msg } ) ] ) );
		}
	}

	function errorMessage( err ) {
		return ( err && err.message ) || i18n.error;
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

	function laneOf( card ) {
		return card.lane || UNSORTED;
	}

	function laneLabel( value ) {
		return lanes[ value ] ? lanes[ value ].lane.label : value;
	}

	/** Lanes a card can be moved into: every lane but its own and the read-only Unsorted. */
	function targetsFor( card ) {
		return laneOrder.filter( function ( v ) {
			return v !== laneOf( card ) && ! lanes[ v ].lane.readonly;
		} );
	}

	function cardEl( id ) {
		return root.querySelector( '.bk-card[data-id="' + id + '"]' );
	}

	// ---- rendering -------------------------------------------------------------------------

	function avatar( person, cls ) {
		if ( ! person ) {
			return null;
		}
		return el( 'img', {
			class: 'bk-avatar ' + ( cls || '' ),
			src: safeUrl( person.avatar ) || null,
			alt: '',
			title: person.name,
			width: 24,
			height: 24,
			loading: 'lazy',
		} );
	}

	function renderCard( card ) {
		cards[ card.id ] = card;
		syncPanelLane( card );

		var editUrl = safeUrl( card.edit_url );
		var title = editUrl
			? el( 'a', { class: 'bk-card-title', href: editUrl, text: card.title } )
			: el( 'span', { class: 'bk-card-title', text: card.title } );
		if ( editUrl ) {
			// A plain click opens the card panel; Ctrl/Cmd/Shift/middle-click still open the editor.
			title.addEventListener( 'click', function ( e ) {
				if ( e.button === 0 && ! e.ctrlKey && ! e.metaKey && ! e.shiftKey && ! e.altKey ) {
					e.preventDefault();
					openPanel( card.id );
				}
			} );
		}

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

		var menuBtn = el( 'button', {
			type: 'button',
			class: 'bk-card-menu-btn',
			'aria-haspopup': 'menu',
			'aria-expanded': 'false',
			'aria-label': sprintf( i18n.actions, card.title ),
		}, [ el( 'span', { 'aria-hidden': 'true', text: '⋯' } ) ] );
		menuBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			openMenu( card.id, menuBtn );
		} );

		var thumb = card.thumbnail ? el( 'img', { class: 'bk-thumb', src: safeUrl( card.thumbnail ) || null, alt: '', loading: 'lazy' } ) : null;

		var node = el( 'li', {
			class: 'bk-card' + ( panel && panel.id === card.id ? ' is-open' : '' ),
			role: 'listitem',
			tabindex: 0,
			'data-id': card.id,
			'aria-label': card.title,
			'aria-describedby': 'bk-card-help',
		}, [ thumb, el( 'div', { class: 'bk-card-head' }, [ title, menuBtn ] ), meta.childNodes.length ? meta : null, people.childNodes.length ? people : null ] );
		node.addEventListener( 'keydown', onCardKey );
		node.addEventListener( 'focusout', function ( e ) {
			// Leaving the card (not into its own menu button) cancels a keyboard pick-up.
			if ( picked && picked.id === card.id && ! node.contains( e.relatedTarget ) ) {
				cancelPick( true );
			}
		} );
		return node;
	}

	function countText( lane, count ) {
		if ( lane.wip > 0 ) {
			return count > lane.wip ? sprintf( i18n.overWip, count, lane.wip ) : count + ' / ' + lane.wip;
		}
		return String( count );
	}

	function setCount( value, delta ) {
		var l = lanes[ value ];
		if ( ! l ) {
			return;
		}
		l.count = Math.max( 0, l.count + delta );
		l.countEl.textContent = countText( l.lane, l.count );
		l.countEl.classList.toggle( 'is-over', l.lane.wip > 0 && l.count > l.lane.wip );
		l.list.setAttribute( 'aria-label', sprintf( i18n.laneLabel, l.lane.label, l.count ) );
	}

	/** Keep the "No cards" placeholder in step with the list's real cards. */
	function syncEmpty( value ) {
		var l = lanes[ value ];
		if ( ! l ) {
			return;
		}
		var empty = l.list.querySelector( '.bk-empty' );
		var hasCards = !! l.list.querySelector( '.bk-card' );
		if ( hasCards && empty ) {
			empty.remove();
		} else if ( ! hasCards && ! empty && l.loaded ) {
			l.list.appendChild( el( 'li', { class: 'bk-empty', text: i18n.empty } ) );
		}
	}

	function renderLane( lane ) {
		var listId = 'bk-lane-' + lane.value;
		var countEl = el( 'span', { class: 'bk-lane-count', text: countText( lane, lane.count ) } );
		var toggle = el( 'button', {
			type: 'button',
			class: 'bk-lane-toggle',
			'aria-expanded': 'true',
			'aria-controls': listId,
		}, [ el( 'span', { class: 'bk-lane-label', text: lane.label } ), countEl ] );

		var list = el( 'ol', { id: listId, class: 'bk-cards', role: 'list', 'data-lane': lane.value } );
		var more = el( 'button', { type: 'button', class: 'button bk-more', hidden: true, text: i18n.loadMore } );
		var section = el( 'section', {
			class: 'bk-lane' + ( lane.readonly ? ' is-readonly' : '' ),
			'data-lane': lane.value,
			style: lane.color ? '--bk-lane-color:' + lane.color : null,
		}, [
			el( 'h2', { class: 'bk-lane-head' }, [ toggle ] ),
			// Say what a lane does to the post, so nobody drags to "Published" expecting a label.
			lane.sets_status && i18n.sets[ lane.sets_status ] ? el( 'p', { class: 'bk-lane-sets', text: i18n.sets[ lane.sets_status ] } ) : null,
			list,
			more,
			lane.can_create ? addCardControl( lane ) : null,
		] );

		var state = { lane: lane, section: section, list: list, countEl: countEl, more: more, count: lane.count, next: 0, loaded: false };
		lanes[ lane.value ] = state;
		laneOrder.push( lane.value );
		setCount( lane.value, 0 );

		toggle.addEventListener( 'click', function () {
			var open = toggle.getAttribute( 'aria-expanded' ) === 'true';
			toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
			section.classList.toggle( 'is-collapsed', open );
		} );
		more.addEventListener( 'click', function () {
			loadLane( lane.value, true );
		} );
		return section;
	}

	function loadLane( value, announce ) {
		var l = lanes[ value ];
		l.more.disabled = true;
		l.list.setAttribute( 'aria-busy', 'true' );
		return apiFetch( { path: base + '/lanes/' + encodeURIComponent( value ) + '/cards?offset=' + l.next + filterQuery( '&' ) } )
			.then( function ( res ) {
				res.cards.forEach( function ( c ) {
					// A card moved here optimistically may come back in the page: don't duplicate it.
					if ( ! cardEl( c.id ) ) {
						l.list.appendChild( renderCard( c ) );
					}
				} );
				l.loaded = true;
				l.next = res.next_offset;
				l.more.hidden = l.next === null;
				l.more.disabled = false;
				l.list.removeAttribute( 'aria-busy' );
				syncEmpty( value );
				if ( announce ) {
					speak( i18n.loadedMore );
				}
			} )
			.catch( function ( err ) {
				l.more.disabled = false;
				l.list.removeAttribute( 'aria-busy' );
				showNotice( errorMessage( err ) );
				speak( errorMessage( err ) );
			} );
	}

	// ---- moving ----------------------------------------------------------------------------

	/**
	 * Move a card to a lane: optimistic, then confirmed or rolled back.
	 *
	 * @param {number} id
	 * @param {string} to   lane value
	 * @param {Object} [dragged] { from: <ol>, index: n } when SortableJS already moved the node
	 */
	function moveCard( id, to, dragged ) {
		var card = cards[ id ];
		var node = cardEl( id );
		if ( ! card || ! node || ! lanes[ to ] ) {
			return;
		}
		var from = laneOf( card );
		if ( to === from || lanes[ to ].lane.readonly ) {
			if ( dragged ) {
				putBack( node, dragged.from, dragged.index );
			}
			return;
		}
		var hadFocus = node.contains( document.activeElement );

		// Where to put it back if the server says no.
		var home = dragged ? { list: dragged.from, index: dragged.index } : { list: node.parentNode, index: indexIn( node ) };

		if ( ! dragged ) {
			lanes[ to ].list.insertBefore( node, lanes[ to ].list.firstChild );
		}
		setCount( from, -1 );
		setCount( to, +1 );
		syncEmpty( from );
		syncEmpty( to );
		if ( hadFocus ) {
			node.focus();
		}

		/** Undo the optimistic move exactly. */
		function rollback() {
			setCount( to, -1 );
			setCount( from, +1 );
			node.classList.remove( 'is-saving' );
			node.removeAttribute( 'aria-busy' );
			putBack( node, home.list, home.index );
			syncEmpty( from );
			syncEmpty( to );
			syncPanelLane( card );
			node.focus();
		}

		// A lane that schedules the post needs a date first: ask on the card (decision 2026-11-04).
		if ( lanes[ to ].lane.sets_status === 'future' ) {
			askDate( node, card, laneLabel( to ), function ( date ) {
				send( date );
			}, function () {
				rollback();
				speak( i18n.cancelled );
			} );
			return;
		}
		send( '' );

		function send( date ) {
		node.classList.add( 'is-saving' );
		node.setAttribute( 'aria-busy', 'true' );
		apiFetch( {
			path: base + '/cards/' + id + '/move',
			method: 'POST',
			data: { to: to, from: from, modified: card.modified_gmt, date: date },
		} ).then( function ( res ) {
			var fresh = renderCard( res.card );
			node.replaceWith( fresh );
			if ( hadFocus ) {
				fresh.focus();
			}
			showNotice( '' );
			var moved = sprintf( i18n.moved, res.card.title, laneLabel( to ) );
			if ( res.card.status && res.card.status.value !== card.status.value ) {
				moved += ' ' + sprintf( i18n.statusNow, res.card.status.label );
			}
			speak( moved );
		} ).catch( function ( err ) {
			// The server wants a (future) date — e.g. one in the past was picked. Ask again, in place.
			if ( err && err.code === 'bk_needs_date' && lanes[ to ].lane.sets_status === 'future' ) {
				node.classList.remove( 'is-saving' );
				node.removeAttribute( 'aria-busy' );
				showNotice( errorMessage( err ) );
				askDate( node, card, laneLabel( to ), function ( d ) {
					send( d );
				}, function () {
					rollback();
					speak( i18n.cancelled );
				} );
				return;
			}
			var current = err && err.code === 'bk_conflict' && err.data && err.data.card;
			if ( current ) {
				// Someone else moved or changed it: show it where it really is now.
				setCount( to, -1 );
				setCount( from, +1 );
				var real = laneOf( current );
				var fresh = renderCard( current );
				node.remove();
				setCount( from, -1 );
				if ( lanes[ real ] ) {
					lanes[ real ].list.insertBefore( fresh, lanes[ real ].list.firstChild );
					setCount( real, +1 );
					if ( hadFocus ) {
						fresh.focus();
					}
				}
				syncEmpty( real );
				syncEmpty( from );
				syncEmpty( to );
			} else {
				rollback();
			}
			showNotice( errorMessage( err ), current ? 'warning' : 'error' );
			speak( errorMessage( err ) );
		} );
		}
	}

	// ---- schedule date picker ---------------------------------------------------------------

	/** 'YYYY-MM-DDTHH:MM' for a datetime-local input. */
	function localInputValue( d ) {
		function p( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		}
		return d.getFullYear() + '-' + p( d.getMonth() + 1 ) + '-' + p( d.getDate() ) + 'T' + p( d.getHours() ) + ':' + p( d.getMinutes() );
	}

	/**
	 * Inline "when?" form on a card dropped into a scheduling lane. The date is SITE time (the
	 * server converts it); the label names the site's timezone so nobody schedules in their own.
	 */
	function askDate( node, card, laneName, onConfirm, onCancel ) {
		var existing = card.date && card.status && card.status.value === 'future' ? card.date.replace( ' ', 'T' ) : '';
		var tomorrow = new Date();
		tomorrow.setDate( tomorrow.getDate() + 1 );
		tomorrow.setHours( 9, 0, 0, 0 );
		var inputId = 'bk-when-' + card.id;

		var input = el( 'input', { id: inputId, type: 'datetime-local', class: 'bk-when-input', required: true, value: existing || localInputValue( tomorrow ) } );
		var ok = el( 'button', { type: 'submit', class: 'button button-primary button-small', text: i18n.schedule } );
		var no = el( 'button', { type: 'button', class: 'button button-small', text: i18n.cancel } );
		var form = el( 'form', { class: 'bk-when', 'aria-label': sprintf( i18n.whenLabel, card.title ) }, [
			el( 'label', { for: inputId, text: sprintf( i18n.when, laneName ) } ),
			input,
			el( 'span', { class: 'bk-when-tz', text: sprintf( i18n.siteTime, timezone ) } ),
			el( 'div', { class: 'bk-when-actions' }, [ ok, no ] ),
		] );

		var done = false;
		function finish( confirmed ) {
			if ( done ) {
				return;
			}
			done = true;
			var value = input.value;
			form.remove();
			if ( confirmed ) {
				onConfirm( value.replace( 'T', ' ' ) );
			} else {
				onCancel();
			}
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( input.value ) {
				finish( true );
			}
		} );
		no.addEventListener( 'click', function () {
			finish( false );
		} );
		form.addEventListener( 'keydown', function ( e ) {
			e.stopPropagation(); // keep the card's pick-up keys out of the form
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				finish( false );
			}
		} );

		node.appendChild( form );
		input.focus();
		speak( sprintf( i18n.whenLabel, card.title ) );
	}

	function indexIn( node ) {
		return Array.prototype.indexOf.call( node.parentNode.children, node );
	}

	function putBack( node, list, index ) {
		var ref = list.children[ index ] || null;
		if ( ref === node ) {
			return;
		}
		list.insertBefore( node, ref );
	}

	// ---- keyboard pick-up / drop (spec §5.5) -----------------------------------------------

	function onCardKey( e ) {
		var node = e.currentTarget;
		// Keys typed on the title link or menu button keep their own meaning, except Escape.
		var onControl = e.target !== node;
		var id = +node.getAttribute( 'data-id' );

		if ( picked && picked.id === id ) {
			if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
				e.preventDefault();
				stepTarget( e.key === 'ArrowRight' ? 1 : -1 );
			} else if ( e.key === ' ' || e.key === 'Enter' ) {
				e.preventDefault();
				var to = picked.target;
				cancelPick( false );
				moveCard( id, to );
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				cancelPick( true );
			}
			return;
		}
		if ( onControl ) {
			return;
		}
		if ( e.key === ' ' || e.key === 'Enter' ) {
			e.preventDefault(); // Space would scroll the page.
			pickUp( id );
		} else if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			var sib = e.key === 'ArrowDown' ? node.nextElementSibling : node.previousElementSibling;
			if ( sib && sib.classList.contains( 'bk-card' ) ) {
				sib.focus();
			}
		}
	}

	function pickUp( id ) {
		var card = cards[ id ];
		if ( targetsFor( card ).length === 0 ) {
			return;
		}
		picked = { id: id, target: laneOf( card ) };
		cardEl( id ).classList.add( 'is-picked' );
		cardEl( id ).setAttribute( 'aria-grabbed', 'true' );
		hint.textContent = sprintf( i18n.hint, card.title );
		hint.hidden = false;
		speak( sprintf( i18n.pickedUp, card.title, laneLabel( laneOf( card ) ) ) );
	}

	/** ←/→ through the lanes a card may enter, plus its own lane (dropping there = no move). */
	function stepTarget( dir ) {
		var card = cards[ picked.id ];
		var choices = laneOrder.filter( function ( v ) {
			return v === laneOf( card ) || ! lanes[ v ].lane.readonly;
		} );
		var i = choices.indexOf( picked.target );
		var next = choices[ Math.min( choices.length - 1, Math.max( 0, i + dir ) ) ];
		if ( next === picked.target ) {
			return;
		}
		highlight( picked.target, false );
		picked.target = next;
		highlight( next, true );
		speak( next === laneOf( card ) ? sprintf( i18n.targetSame, laneLabel( next ) ) : laneLabel( next ) );
	}

	function highlight( value, on ) {
		if ( lanes[ value ] ) {
			lanes[ value ].section.classList.toggle( 'is-target', on );
		}
	}

	function cancelPick( announce ) {
		if ( ! picked ) {
			return;
		}
		var node = cardEl( picked.id );
		if ( node ) {
			node.classList.remove( 'is-picked' );
			node.removeAttribute( 'aria-grabbed' );
		}
		highlight( picked.target, false );
		picked = null;
		hint.hidden = true;
		if ( announce ) {
			speak( i18n.cancelled );
		}
	}

	// ---- ⋯ menu ----------------------------------------------------------------------------

	var openMenuState = null;

	function closeMenu( focusButton ) {
		if ( ! openMenuState ) {
			return;
		}
		openMenuState.menu.remove();
		openMenuState.button.setAttribute( 'aria-expanded', 'false' );
		if ( focusButton ) {
			openMenuState.button.focus();
		}
		openMenuState = null;
	}

	function openMenu( id, button ) {
		var wasOpen = openMenuState && openMenuState.button === button;
		closeMenu( false );
		if ( wasOpen ) {
			button.focus();
			return;
		}
		var card = cards[ id ];
		var items = [];
		if ( safeUrl( card.edit_url ) ) {
			items.push( { label: i18n.open, action: openPanel } );
			items.push( { label: i18n.openEditor, href: safeUrl( card.edit_url ) } );
		}
		if ( safeUrl( card.view_url ) ) {
			items.push( { label: i18n.view, href: safeUrl( card.view_url ), newTab: true } );
		}
		if ( safeUrl( card.edit_url ) ) {
			// Only people who can edit the post get these (the server checks again).
			if ( card.fields.indexOf( 'assignees' ) !== -1 ) {
				items.push( { label: i18n.assign, action: openAssign } );
			}
			if ( card.fields.indexOf( 'due' ) !== -1 ) {
				items.push( { label: i18n.setDue, action: openDue } );
			}
		}
		targetsFor( card ).forEach( function ( v ) {
			items.push( { label: sprintf( i18n.moveTo, laneLabel( v ) ), lane: v } );
		} );

		var menu = el( 'ul', { class: 'bk-menu', role: 'menu', 'aria-label': sprintf( i18n.actions, card.title ) } );
		items.forEach( function ( item ) {
			var control = item.href
				? el( 'a', { role: 'menuitem', href: item.href, tabindex: -1, target: item.newTab ? '_blank' : null, rel: item.newTab ? 'noopener' : null, text: item.label } )
				: el( 'button', { type: 'button', role: 'menuitem', tabindex: -1, text: item.label } );
			if ( item.action ) {
				control.addEventListener( 'click', function () {
					closeMenu( false );
					item.action( id );
				} );
			}
			if ( item.lane ) {
				control.addEventListener( 'click', function () {
					closeMenu( false );
					moveCard( id, item.lane );
					var moved = cardEl( id );
					if ( moved ) {
						moved.focus();
					}
				} );
			}
			menu.appendChild( el( 'li', { role: 'none' }, [ control ] ) );
		} );
		menu.addEventListener( 'keydown', function ( e ) {
			var controls = Array.prototype.slice.call( menu.querySelectorAll( '[role="menuitem"]' ) );
			var i = controls.indexOf( document.activeElement );
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				controls[ ( i + ( e.key === 'ArrowDown' ? 1 : -1 ) + controls.length ) % controls.length ].focus();
			} else if ( e.key === 'Home' || e.key === 'End' ) {
				e.preventDefault();
				controls[ e.key === 'Home' ? 0 : controls.length - 1 ].focus();
			} else if ( e.key === 'Escape' || e.key === 'Tab' ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
				}
				e.stopPropagation();
				closeMenu( e.key === 'Escape' );
			}
		} );

		// Float the menu on the page, anchored to the button. Inside the card it was clipped by the
		// lane's scrolling list — in a short lane (e.g. filtered to one card) in BOTH directions,
		// and it ended up under the lane header where it couldn't be clicked.
		document.body.appendChild( menu );
		placeMenu( menu, button );
		button.setAttribute( 'aria-expanded', 'true' );
		openMenuState = { menu: menu, button: button };
		var first = menu.querySelector( '[role="menuitem"]' );
		if ( first ) {
			first.focus();
		}
	}

	/** Below the button, right-aligned to it; above it if there's no room below; kept on screen. */
	function placeMenu( menu, button ) {
		var r = button.getBoundingClientRect();
		var m = menu.getBoundingClientRect();
		var gap = 4;
		var top = r.bottom + gap;
		if ( top + m.height > window.innerHeight - gap && r.top - gap - m.height >= gap ) {
			top = r.top - gap - m.height;
		}
		var left = Math.min( Math.max( gap, r.right - m.width ), window.innerWidth - m.width - gap );
		menu.style.top = Math.max( gap, top ) + 'px';
		menu.style.left = left + 'px';
	}

	document.addEventListener( 'click', function ( e ) {
		if ( openMenuState && ! openMenuState.menu.contains( e.target ) ) {
			closeMenu( false );
		}
	} );
	// A floating menu would drift away from its card on scroll: close it instead.
	window.addEventListener( 'scroll', function ( e ) {
		if ( openMenuState && ! openMenuState.menu.contains( e.target ) ) {
			closeMenu( false );
		}
	}, true );
	window.addEventListener( 'resize', function () {
		closeMenu( false );
	} );

	// ---- drag and drop ---------------------------------------------------------------------

	function enableDrag() {
		if ( ! window.Sortable || mobile.matches ) {
			return;
		}
		laneOrder.forEach( function ( value ) {
			var l = lanes[ value ];
			sortables.push( window.Sortable.create( l.list, {
				group: {
					name: 'bk-' + config.board,
					pull: true,
					put: ! l.lane.readonly, // Never drop INTO Unsorted (spec §4.3).
				},
				sort: false, // Lanes are sorted by a field; no hand ordering in v1.
				draggable: '.bk-card',
				filter: '.bk-card-menu-btn, .bk-menu, .bk-when, .bk-form',
				preventOnFilter: false,
				ghostClass: 'is-ghost',
				chosenClass: 'is-chosen',
				animation: window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 0 : 120,
				onStart: function () {
					closeMenu( false );
					cancelPick( false );
				},
				onEnd: function ( evt ) {
					if ( evt.from === evt.to ) {
						return;
					}
					var dragged = { from: evt.from, index: evt.oldIndex };
					var to = evt.to.getAttribute( 'data-lane' );
					// Sortable leaves the card in the last lane that ACCEPTED it while passing over.
					// Dragging toward a lane that refuses drops (Unsorted) would silently move it to
					// whichever lane was crossed on the way. Only move if the pointer was released over
					// the lane Sortable chose; otherwise put the card back.
					var dropLane = laneUnderPointer( evt.originalEvent );
					if ( dropLane !== null && dropLane !== to ) {
						putBack( evt.item, evt.from, evt.oldIndex );
						speak( i18n.cancelled );
						return;
					}
					moveCard( +evt.item.getAttribute( 'data-id' ), to, dragged );
				},
			} ) );
		} );
	}

	/** Lane value under the point where a drag ended; null if unknown (e.g. no coordinates). */
	function laneUnderPointer( e ) {
		var point = e && e.changedTouches && e.changedTouches[ 0 ] ? e.changedTouches[ 0 ] : e;
		if ( ! point || typeof point.clientX !== 'number' ) {
			return null;
		}
		var hit = document.elementFromPoint( point.clientX, point.clientY );
		var section = hit && hit.closest ? hit.closest( '.bk-lane' ) : null;
		return section ? section.getAttribute( 'data-lane' ) : '';
	}

	function disableDrag() {
		sortables.forEach( function ( s ) {
			s.destroy();
		} );
		sortables = [];
	}

	// Below 782px moves go through the ⋯ menu only (spec §5.3).
	if ( mobile.addEventListener ) {
		mobile.addEventListener( 'change', function () {
			disableDrag();
			enableDrag();
		} );
	}

	// ---- card forms: assign, due date --------------------------------------------------------

	/** Replace a card with the server's version after a field save (or a 409). */
	function swapCard( id, fresh ) {
		var node = cardEl( id );
		if ( ! node ) {
			return;
		}
		var focus = node.contains( document.activeElement );
		var next = renderCard( fresh );
		node.replaceWith( next );
		if ( focus ) {
			next.focus();
		}
	}

	/** A small form on a card. onSave( close, retry ) does the request. */
	function cardForm( id, label, fields, onSave, extraButtons ) {
		var node = cardEl( id );
		if ( ! node || node.querySelector( '.bk-when, .bk-form' ) ) {
			return null;
		}
		var save = el( 'button', { type: 'submit', class: 'button button-primary button-small', text: i18n.save } );
		var cancel = el( 'button', { type: 'button', class: 'button button-small', text: i18n.cancel } );
		var form = el( 'form', { class: 'bk-form', 'aria-label': label }, fields.concat( [ el( 'div', { class: 'bk-when-actions' }, [ save ].concat( extraButtons || [] ).concat( [ cancel ] ) ) ] ) );
		function close() {
			form.remove();
			var n = cardEl( id );
			if ( n ) {
				n.focus();
			}
		}
		cancel.addEventListener( 'click', close );
		form.addEventListener( 'keydown', function ( e ) {
			e.stopPropagation(); // keep the card's pick-up keys out of the form
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				close();
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			save.disabled = true;
			onSave( close, function () {
				save.disabled = false;
			} );
		} );
		node.appendChild( form );
		return form;
	}

	function fieldError( err, id, close ) {
		var current = err && err.code === 'bk_conflict' && err.data && err.data.card;
		if ( current ) {
			close();
			swapCard( id, current );
		}
		showNotice( errorMessage( err ), current ? 'warning' : 'error' );
		speak( errorMessage( err ) );
	}

	var peopleCache = {};
	function fetchPeople( search ) {
		var key = search || '';
		if ( ! peopleCache[ key ] ) {
			peopleCache[ key ] = apiFetch( { path: base + '/people?search=' + encodeURIComponent( key ) } ).then( function ( r ) {
				return r.people;
			} );
		}
		return peopleCache[ key ];
	}

	function openAssign( id ) {
		var card = cards[ id ];
		var chosen = {};
		( card.assignees || [] ).forEach( function ( p ) {
			chosen[ p.id ] = p;
		} );
		var searchId = 'bk-assign-search-' + id;
		var search = el( 'input', { id: searchId, type: 'search', class: 'bk-when-input', placeholder: i18n.searchPeople } );
		var list = el( 'div', { class: 'bk-people', role: 'group', 'aria-label': i18n.assignees } );

		function draw( people ) {
			list.textContent = '';
			var shown = {};
			Object.keys( chosen ).map( function ( k ) {
				return chosen[ k ];
			} ).concat( people ).forEach( function ( p ) {
				if ( shown[ p.id ] ) {
					return;
				}
				shown[ p.id ] = true;
				var box = el( 'input', { type: 'checkbox', value: p.id, checked: !! chosen[ p.id ] } );
				box.addEventListener( 'change', function () {
					if ( box.checked ) {
						chosen[ p.id ] = p;
					} else {
						delete chosen[ p.id ];
					}
				} );
				list.appendChild( el( 'label', { class: 'bk-person' }, [ box, ' ', p.name ] ) );
			} );
			if ( ! list.childNodes.length ) {
				list.appendChild( el( 'p', { class: 'bk-when-tz', text: i18n.noPeople } ) );
			}
		}

		var timer = null;
		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				fetchPeople( search.value.trim() ).then( draw );
			}, 250 );
		} );

		var form = cardForm( id, sprintf( i18n.assignLabel, card.title ), [
			el( 'label', { for: searchId, text: i18n.assignees } ),
			search,
			list,
		], function ( close, retry ) {
			apiFetch( {
				path: base + '/cards/' + id + '/assignees',
				method: 'POST',
				data: {
					user_ids: Object.keys( chosen ).map( Number ),
					previous: ( card.assignees || [] ).map( function ( p ) {
						return p.id;
					} ),
				},
			} ).then( function ( res ) {
				close();
				swapCard( id, res.card );
				showNotice( '' );
				speak( i18n.saved );
			} ).catch( function ( err ) {
				retry();
				fieldError( err, id, close );
			} );
		} );
		if ( form ) {
			fetchPeople( '' ).then( draw );
			search.focus();
		}
	}

	function openDue( id ) {
		var card = cards[ id ];
		var inputId = 'bk-due-' + id;
		var previous = ( card.due && card.due.date ) || '';
		var input = el( 'input', { id: inputId, type: 'date', class: 'bk-when-input', value: previous } );
		var clear = el( 'button', { type: 'button', class: 'button button-small', text: i18n.clear } );
		var form;
		function send( date, close, retry ) {
			apiFetch( {
				path: base + '/cards/' + id + '/due',
				method: 'POST',
				data: { date: date, previous: previous },
			} ).then( function ( res ) {
				close();
				swapCard( id, res.card );
				showNotice( '' );
				speak( i18n.saved );
			} ).catch( function ( err ) {
				retry();
				fieldError( err, id, close );
			} );
		}
		form = cardForm( id, sprintf( i18n.dueLabel, card.title ), [
			el( 'label', { for: inputId, text: i18n.dueDate } ),
			input,
		], function ( close, retry ) {
			send( input.value, close, retry );
		}, [ clear ] );
		if ( form ) {
			clear.addEventListener( 'click', function () {
				send( '', function () {
					form.remove();
				}, function () {} );
			} );
			input.focus();
		}
	}

	// ---- filter bar (kept in the URL, spec §5.3) ----------------------------------------------

	var FILTER_KEYS = [ 's', 'assignee', 'author', 'overdue' ];
	var filters = {};
	( function readUrl() {
		var params = new URLSearchParams( window.location.search );
		FILTER_KEYS.forEach( function ( k ) {
			if ( params.get( k ) ) {
				filters[ k ] = params.get( k );
			}
		} );
	}() );

	/** '?s=…&assignee=me' (or with another lead such as '&'); '' when there are no filters. */
	function filterQuery( lead ) {
		var parts = FILTER_KEYS.filter( function ( k ) {
			return filters[ k ];
		} ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( filters[ k ] );
		} );
		return parts.length ? ( lead || '?' ) + parts.join( '&' ) : '';
	}

	function writeUrl() {
		var params = new URLSearchParams( window.location.search );
		FILTER_KEYS.forEach( function ( k ) {
			if ( filters[ k ] ) {
				params.set( k, filters[ k ] );
			} else {
				params.delete( k );
			}
		} );
		window.history.replaceState( null, '', window.location.pathname + '?' + params.toString() );
	}

	function setFilter( key, value ) {
		if ( value ) {
			filters[ key ] = value;
		} else {
			delete filters[ key ];
		}
		writeUrl();
		loadBoard( true );
	}

	function buildFilterBar() {
		function field( label, control ) {
			return el( 'label', { class: 'bk-filter' }, [ el( 'span', { class: 'bk-filter-label', text: label } ), control ] );
		}
		var search = el( 'input', { type: 'search', class: 'bk-filter-search', value: filters.s || '', placeholder: i18n.searchCards } );
		var timer = null;
		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				setFilter( 's', search.value.trim() );
			}, 350 );
		} );

		var assignee = el( 'select', {}, [
			el( 'option', { value: '', text: i18n.anyone } ),
			el( 'option', { value: 'me', text: i18n.me } ),
		] );
		var author = el( 'select', {}, [ el( 'option', { value: '', text: i18n.anyone } ) ] );
		fetchPeople( '' ).then( function ( people ) {
			people.forEach( function ( p ) {
				assignee.appendChild( el( 'option', { value: p.id, text: p.name } ) );
				author.appendChild( el( 'option', { value: p.id, text: p.name } ) );
			} );
			assignee.value = filters.assignee || '';
			author.value = filters.author || '';
		} ).catch( function () {} );
		assignee.value = filters.assignee || '';
		assignee.addEventListener( 'change', function () {
			setFilter( 'assignee', assignee.value );
		} );
		author.addEventListener( 'change', function () {
			setFilter( 'author', author.value );
		} );

		var overdue = el( 'input', { type: 'checkbox', checked: filters.overdue === '1' } );
		overdue.addEventListener( 'change', function () {
			setFilter( 'overdue', overdue.checked ? '1' : '' );
		} );

		var clearAll = el( 'button', { type: 'button', class: 'button-link bk-filter-clear', text: i18n.clearFilters } );
		clearAll.addEventListener( 'click', function () {
			filters = {};
			search.value = '';
			assignee.value = '';
			author.value = '';
			overdue.checked = false;
			writeUrl();
			loadBoard( true );
		} );

		return el( 'div', { class: 'bk-filters', role: 'search', 'aria-label': i18n.filters }, [
			field( i18n.search, search ),
			field( i18n.assignees, assignee ),
			field( i18n.author, author ),
			el( 'label', { class: 'bk-filter bk-filter-check' }, [ overdue, ' ', i18n.overdueOnly ] ),
			clearAll,
		] );
	}

	// ---- "+ Add card" (PLAN Phase 5) ---------------------------------------------------------

	/** The add button under a lane; the server says which lanes accept new cards (can_create). */
	function addCardControl( lane ) {
		var wrap = el( 'div', { class: 'bk-add' } );
		var button = el( 'button', { type: 'button', class: 'button-link bk-add-btn', text: i18n.addCard, 'aria-label': sprintf( i18n.addTo, lane.label ) } );
		wrap.appendChild( button );
		button.addEventListener( 'click', function () {
			var inputId = 'bk-add-' + lane.value;
			var input = el( 'input', { id: inputId, type: 'text', class: 'bk-when-input', maxlength: 300, autocomplete: 'off' } );
			var ok = el( 'button', { type: 'submit', class: 'button button-primary button-small', text: i18n.add } );
			var no = el( 'button', { type: 'button', class: 'button button-small', text: i18n.cancel } );
			var form = el( 'form', { class: 'bk-form bk-add-form', 'aria-label': sprintf( i18n.addTo, lane.label ) }, [
				el( 'label', { for: inputId, class: 'screen-reader-text', text: i18n.cardTitle } ),
				input,
				el( 'div', { class: 'bk-when-actions' }, [ ok, no ] ),
			] );
			function close() {
				form.remove();
				button.hidden = false;
				button.focus();
			}
			no.addEventListener( 'click', close );
			form.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					close();
				}
			} );
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var title = input.value.trim();
				if ( ! title ) {
					input.focus();
					return;
				}
				ok.disabled = true;
				apiFetch( { path: base + '/cards', method: 'POST', data: { title: title, lane: lane.value } } ).then( function ( res ) {
					ok.disabled = false;
					var where = lanes[ laneOf( res.card ) ] ? laneOf( res.card ) : lane.value;
					if ( lanes[ where ] ) {
						lanes[ where ].list.insertBefore( renderCard( res.card ), lanes[ where ].list.firstChild );
						setCount( where, +1 );
						syncEmpty( where );
					}
					// Stay open for the next one: adding a run of pitches is the common case.
					input.value = '';
					input.focus();
					showNotice( '' );
					speak( sprintf( i18n.added, res.card.title, laneLabel( where ) ) );
				} ).catch( function ( err ) {
					ok.disabled = false;
					showNotice( errorMessage( err ) );
					speak( errorMessage( err ) );
				} );
			} );
			button.hidden = true;
			wrap.appendChild( form );
			input.focus();
		} );
		return wrap;
	}

	// ---- card panel (PLAN Phase 5) ------------------------------------------------------------
	//
	// A non-modal dialog beside the board: the board stays usable (drag, keyboard) while it's
	// open. Details = title, excerpt, featured image, lane. Write = the real post editor in an
	// iframe (bk_panel=1 hides its admin chrome). Unsaved changes are never dropped silently:
	// closing, switching tab or opening another card goes through guard().

	function setUrlCard( id ) {
		var params = new URLSearchParams( window.location.search );
		if ( id ) {
			params.set( 'card', id );
		} else {
			params.delete( 'card' );
		}
		window.history.replaceState( null, '', window.location.pathname + '?' + params.toString() );
	}

	function markOpen( id, on ) {
		var n = cardEl( id );
		if ( n ) {
			n.classList.toggle( 'is-open', on );
		}
	}

	/** Keep the panel's lane select in step with the card (after a move, a rollback or a 409). */
	function syncPanelLane( card ) {
		if ( panel && panel.id === card.id && panel.laneSelect ) {
			panel.laneSelect.value = laneOf( card );
		}
	}

	/** A card came back from the server, maybe in another lane (e.g. the editor changed its stage). */
	function relocate( fresh ) {
		var old = cards[ fresh.id ];
		var node = cardEl( fresh.id );
		if ( ! old || ! node ) {
			return;
		}
		var from = laneOf( old );
		var to = laneOf( fresh );
		if ( from === to || ! lanes[ to ] ) {
			swapCard( fresh.id, fresh );
			return;
		}
		var next = renderCard( fresh );
		node.remove();
		lanes[ to ].list.insertBefore( next, lanes[ to ].list.firstChild );
		setCount( from, -1 );
		setCount( to, +1 );
		syncEmpty( from );
		syncEmpty( to );
	}

	function refreshCard( id ) {
		apiFetch( { path: base + '/cards/' + id } ).then( function ( res ) {
			relocate( res.card );
		} ).catch( function ( err ) {
			// Trashed (or otherwise gone) from inside the editor: take it off the board.
			var node = cardEl( id );
			if ( err && err.code === 'bk_no_post' && node && cards[ id ] ) {
				var from = laneOf( cards[ id ] );
				node.remove();
				setCount( from, -1 );
				syncEmpty( from );
			}
		} );
	}

	function panelNotice( msg, type ) {
		if ( ! panel ) {
			return;
		}
		panel.notice.textContent = '';
		if ( msg ) {
			panel.notice.appendChild( el( 'div', { class: 'notice inline notice-' + ( type || 'error' ) }, [ el( 'p', { text: msg } ) ] ) );
		}
	}

	/** What the editor in the Write tab is holding. Same origin, so its wp.data is reachable. */
	function editorState() {
		var frame = panel && panel.frame;
		if ( ! frame ) {
			return { dirty: false };
		}
		try {
			var w = frame.contentWindow;
			var sel = w.wp && w.wp.data && w.wp.data.select ? w.wp.data.select( 'core/editor' ) : null;
			if ( sel && typeof sel.isEditedPostDirty === 'function' ) {
				return { dirty: !! sel.isEditedPostDirty(), block: true, w: w };
			}
			if ( w.wp && w.wp.autosave && w.wp.autosave.server && w.wp.autosave.server.postChanged ) {
				return { dirty: !! w.wp.autosave.server.postChanged(), block: false, w: w };
			}
		} catch ( e ) {
			// Not loaded yet, or navigated somewhere we can't read: nothing to protect.
		}
		return { dirty: false };
	}

	/** Save the block editor (post and meta boxes); rejects if the save failed. */
	function saveEditor( st ) {
		var data = st.w.wp.data;
		return Promise.resolve( data.dispatch( 'core/editor' ).savePost() ).then( function () {
			if ( ! data.select( 'core/editor' ).didPostSaveRequestSucceed() ) {
				throw new Error( 'save failed' );
			}
			var ep = data.select( 'core/edit-post' );
			function busy() {
				return !! ( ep && ep.isSavingMetaBoxes && ep.isSavingMetaBoxes() );
			}
			return new Promise( function ( resolve ) {
				if ( ! busy() ) {
					resolve();
					return;
				}
				var unsub = data.subscribe( function () {
					if ( ! busy() ) {
						unsub();
						resolve();
					}
				} );
			} );
		} );
	}

	/** Unsaved work in the panel: { dirty, save: fn→Promise|null, msg }. */
	function dirtyState() {
		if ( ! panel ) {
			return { dirty: false };
		}
		if ( panel.tab === 'write' ) {
			var st = editorState();
			return {
				dirty: st.dirty,
				save: st.block ? function () {
					return saveEditor( st );
				} : null,
				msg: st.block ? pi18n.unsaved : pi18n.unsaved + ' ' + pi18n.saveFirst,
			};
		}
		if ( panel.form && panel.form.dirty() ) {
			return { dirty: true, save: panel.form.save, msg: pi18n.unsavedDetails };
		}
		return { dirty: false };
	}

	/** Run next() now, or — if there are unsaved changes — after the user saves or discards them. */
	function guard( next ) {
		var st = dirtyState();
		if ( ! st.dirty ) {
			next();
			return;
		}
		var old = panel.root.querySelector( '.bk-panel-guard' );
		if ( old ) {
			old.remove();
		}
		// Where to put focus back on "Keep editing" (the bar's own buttons are about to go).
		var returnTo = panel.root.contains( document.activeElement ) ? document.activeElement : panel.closeBtn;
		var save = st.save ? el( 'button', { type: 'button', class: 'button button-primary button-small', text: pi18n.saveClose } ) : null;
		var discard = el( 'button', { type: 'button', class: 'button button-small', text: pi18n.discard } );
		var keep = el( 'button', { type: 'button', class: 'button button-small', text: pi18n.keepEditing } );
		var bar = el( 'div', { class: 'bk-panel-guard notice notice-warning inline', role: 'alert' }, [
			el( 'p', { text: st.msg } ),
			el( 'div', { class: 'bk-when-actions' }, [ save, discard, keep ] ),
		] );
		if ( save ) {
			save.addEventListener( 'click', function () {
				save.disabled = true;
				st.save().then( function () {
					bar.remove();
					next();
				}, function () {
					save.disabled = false;
				} );
			} );
		}
		discard.addEventListener( 'click', function () {
			bar.remove();
			if ( panel ) {
				panel.form = null; // drop the details edits too
				panel.frame = null;
			}
			next();
		} );
		keep.addEventListener( 'click', function () {
			bar.remove();
			if ( panel && panel.frame ) {
				panel.frame.focus();
			} else if ( returnTo && document.body.contains( returnTo ) ) {
				returnTo.focus();
			}
		} );
		panel.head.parentNode.insertBefore( bar, panel.head.nextSibling );
		( save || discard ).focus();
	}

	function openPanel( id ) {
		if ( panel && panel.id === id ) {
			panel.closeBtn.focus();
			return;
		}
		if ( panel ) {
			guard( function () {
				closePanel( false );
				openPanel( id );
			} );
			return;
		}
		closeMenu( false );
		cancelPick( false );

		var heading = el( 'h2', { id: 'bk-panel-title', class: 'bk-panel-title', text: ( cards[ id ] && cards[ id ].title ) || pi18n.loading } );
		var closeBtn = el( 'button', { type: 'button', class: 'button bk-panel-close', text: pi18n.close } );
		var head = el( 'div', { class: 'bk-panel-head' }, [ heading, closeBtn ] );
		var tabs = el( 'div', { class: 'bk-panel-tabs', role: 'tablist', 'aria-labelledby': 'bk-panel-title' } );
		var noticeEl = el( 'div', { class: 'bk-panel-notice', role: 'status', 'aria-live': 'polite' } );
		var body = el( 'div', { id: 'bk-panel-body', class: 'bk-panel-body', role: 'tabpanel', tabindex: -1 }, [ el( 'p', { class: 'bk-loading', text: pi18n.loading } ) ] );
		var rootEl = el( 'aside', { class: 'bk-panel', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'bk-panel-title' }, [ head, tabs, noticeEl, body ] );

		panel = { id: id, root: rootEl, head: head, heading: heading, closeBtn: closeBtn, tabs: tabs, notice: noticeEl, body: body, tab: 'details', data: null, frame: null, form: null, laneSelect: null };
		closeBtn.addEventListener( 'click', requestClose );
		rootEl.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! e.defaultPrevented ) {
				e.preventDefault();
				requestClose();
			}
		} );
		document.body.appendChild( rootEl );
		document.body.classList.add( 'bk-panel-open' );
		markOpen( id, true );
		setUrlCard( id );
		closeBtn.focus();
		loadPanel( id );
	}

	function requestClose() {
		guard( function () {
			closePanel( true );
		} );
	}

	function closePanel( focusCard ) {
		if ( ! panel ) {
			return;
		}
		var id = panel.id;
		var wasWriting = panel.tab === 'write';
		panel.root.remove();
		document.body.classList.remove( 'bk-panel-open', 'bk-panel-writing' );
		markOpen( id, false );
		panel = null;
		setUrlCard( '' );
		if ( wasWriting ) {
			refreshCard( id ); // the editor may have changed the title, image, stage…
		}
		var n = cardEl( id );
		if ( focusCard && n ) {
			n.focus();
		}
	}

	function loadPanel( id ) {
		return apiFetch( { path: base + '/cards/' + id } ).then( function ( res ) {
			if ( ! panel || panel.id !== id ) {
				return;
			}
			panel.data = res;
			panel.heading.textContent = res.card.title;
			relocate( res.card );
			buildTabs();
			showTab( panel.tab === 'write' ? 'details' : panel.tab );
		} ).catch( function ( err ) {
			if ( ! panel || panel.id !== id ) {
				return;
			}
			panel.body.textContent = '';
			panelNotice( errorMessage( err ) );
		} );
	}

	function buildTabs() {
		var d = panel.data.details;
		var names = [ 'details' ];
		if ( d.supports.editor && safeUrl( d.editor_url ) ) {
			names.push( 'write' );
		}
		panel.tabs.textContent = '';
		names.forEach( function ( name ) {
			var b = el( 'button', { type: 'button', role: 'tab', class: 'bk-panel-tab', id: 'bk-tab-' + name, 'data-tab': name, 'aria-controls': 'bk-panel-body', text: pi18n[ name ] } );
			b.addEventListener( 'click', function () {
				if ( panel.tab !== name ) {
					guard( function () {
						showTab( name );
					} );
				}
			} );
			b.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
					e.preventDefault();
					var i = names.indexOf( name ) + ( e.key === 'ArrowRight' ? 1 : -1 );
					var other = panel.tabs.querySelector( '[data-tab="' + names[ ( i + names.length ) % names.length ] + '"]' );
					if ( other ) {
						other.focus();
						other.click();
					}
				}
			} );
			panel.tabs.appendChild( b );
		} );
	}

	function showTab( name ) {
		var leavingEditor = panel.tab === 'write' && name !== 'write' && panel.data;
		panel.tab = name;
		Array.prototype.forEach.call( panel.tabs.children, function ( b ) {
			var on = b.getAttribute( 'data-tab' ) === name;
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			b.tabIndex = on ? 0 : -1;
		} );
		panel.body.setAttribute( 'aria-labelledby', 'bk-tab-' + name );
		panel.frame = null;
		panel.form = null;
		panel.laneSelect = null;
		panel.body.textContent = '';
		panelNotice( '' );
		document.body.classList.toggle( 'bk-panel-writing', name === 'write' );
		if ( name === 'write' ) {
			renderWrite();
		} else if ( leavingEditor ) {
			// The editor may have changed the title, excerpt or image: reload before showing them.
			panel.body.appendChild( el( 'p', { class: 'bk-loading', text: pi18n.loading } ) );
			loadPanel( panel.id );
		} else {
			renderDetails();
		}
	}

	/** POST only the changed fields; refresh the panel and the card from the reply. */
	function saveDetails( changes ) {
		var id = panel.id;
		var data = { modified: panel.data.card.modified_gmt };
		Object.keys( changes ).forEach( function ( k ) {
			data[ k ] = changes[ k ];
		} );
		return apiFetch( { path: base + '/cards/' + id + '/details', method: 'POST', data: data } ).then( function ( res ) {
			if ( panel && panel.id === id ) {
				panel.data = res;
				panel.heading.textContent = res.card.title;
				panelNotice( pi18n.saved, 'success' );
			}
			relocate( res.card );
			speak( pi18n.saved );
			return res;
		}, function ( err ) {
			var current = err && err.data && err.data.card;
			if ( current && err.code === 'bk_conflict' ) {
				relocate( current );
				if ( panel && panel.id === id ) {
					panel.data = { card: current, details: err.data.details };
					panel.heading.textContent = current.title;
					renderDetails();
					panelNotice( pi18n.conflict, 'warning' );
				}
				speak( pi18n.conflict );
			} else {
				if ( current && panel && panel.id === id && err.data.details ) {
					panel.data.details.locked_by = err.data.details.locked_by; // keep what they typed
				}
				panelNotice( errorMessage( err ) );
				speak( errorMessage( err ) );
			}
			throw err;
		} );
	}

	function renderDetails() {
		panel.body.textContent = '';
		panel.form = null;
		var d = panel.data.details;
		var card = panel.data.card;
		var id = panel.id;
		var locked = !! d.locked_by;
		var form = el( 'form', { class: 'bk-panel-form' } );

		if ( locked ) {
			form.appendChild( el( 'div', { class: 'notice notice-warning inline' }, [ el( 'p', { text: sprintf( pi18n.locked, d.locked_by ) } ) ] ) );
		}

		var titleInput = null;
		if ( d.supports.title ) {
			titleInput = el( 'input', { id: 'bk-p-title', type: 'text', class: 'widefat', maxlength: 300, value: d.title } );
			form.appendChild( el( 'p', { class: 'bk-panel-field' }, [ el( 'label', { for: 'bk-p-title', text: pi18n.title } ), titleInput ] ) );
		}

		var excerptInput = null;
		if ( d.supports.excerpt ) {
			excerptInput = el( 'textarea', { id: 'bk-p-excerpt', class: 'widefat', rows: 4, 'aria-describedby': 'bk-p-excerpt-help', text: d.excerpt } );
			form.appendChild( el( 'p', { class: 'bk-panel-field' }, [
				el( 'label', { for: 'bk-p-excerpt', text: pi18n.excerpt } ),
				excerptInput,
				el( 'span', { id: 'bk-p-excerpt-help', class: 'bk-panel-help', text: pi18n.excerptHelp } ),
			] ) );
		}

		// Phase 6: the SEO plugin's meta description, if one is active.
		var metaInput = null;
		if ( d.seo ) {
			metaInput = el( 'textarea', { id: 'bk-p-meta', class: 'widefat', rows: 3, maxlength: 320, 'aria-describedby': 'bk-p-meta-help', text: d.seo.description } );
			form.appendChild( el( 'p', { class: 'bk-panel-field' }, [
				el( 'label', { for: 'bk-p-meta', text: sprintf( pi18n.metaLabel, d.seo.plugin ) } ),
				metaInput,
				el( 'span', { id: 'bk-p-meta-help', class: 'bk-panel-help', text: pi18n.metaHelp } ),
			] ) );
		}

		var save = el( 'button', { type: 'submit', class: 'button button-primary', text: pi18n.saveDetails, disabled: locked } );
		if ( titleInput || excerptInput || metaInput ) {
			form.appendChild( el( 'p', { class: 'bk-panel-actions' }, [ save ] ) );
		}

		if ( d.ai && ! locked ) {
			form.appendChild( aiSection( d, { title: titleInput, excerpt: excerptInput, meta: metaInput }, function () {
				return changes();
			} ) );
		}

		// Featured image: chosen in the core media modal, saved at once (like the editor does).
		if ( d.supports.thumbnail ) {
			var imgBox = el( 'div', { class: 'bk-panel-image' } );
			form.appendChild( el( 'div', { class: 'bk-panel-field' }, [ el( 'span', { class: 'bk-panel-label', text: pi18n.featured } ), imgBox ] ) );
			drawImage( imgBox );
		}

		// Lane: the same move as drag / keyboard / menu (needs the card on the board).
		if ( cardEl( id ) && cards[ id ] ) {
			var select = el( 'select', { id: 'bk-p-lane' } );
			laneOrder.forEach( function ( v ) {
				var l = lanes[ v ].lane;
				if ( ! l.readonly || v === laneOf( card ) ) {
					select.appendChild( el( 'option', { value: v, text: l.label, disabled: l.readonly } ) );
				}
			} );
			select.value = laneOf( cards[ id ] );
			select.addEventListener( 'change', function () {
				moveCard( id, select.value );
			} );
			panel.laneSelect = select;
			form.appendChild( el( 'p', { class: 'bk-panel-field' }, [ el( 'label', { for: 'bk-p-lane', text: pi18n.lane } ), select ] ) );
		}

		var links = el( 'p', { class: 'bk-panel-links' } );
		if ( safeUrl( card.edit_url ) ) {
			links.appendChild( el( 'a', { href: safeUrl( card.edit_url ), text: pi18n.fullEditor } ) );
		}
		if ( safeUrl( card.view_url ) ) {
			links.appendChild( document.createTextNode( ' · ' ) );
			links.appendChild( el( 'a', { href: safeUrl( card.view_url ), target: '_blank', rel: 'noopener', text: i18n.view } ) );
		}
		form.appendChild( links );

		function changes() {
			var c = {};
			if ( titleInput && titleInput.value.trim() !== d.title.trim() ) {
				c.title = titleInput.value;
			}
			if ( excerptInput && excerptInput.value.trim() !== d.excerpt.trim() ) {
				c.excerpt = excerptInput.value;
			}
			if ( metaInput && metaInput.value.trim() !== d.seo.description.trim() ) {
				c.meta_description = metaInput.value;
			}
			return c;
		}
		panel.form = {
			dirty: function () {
				return Object.keys( changes() ).length > 0;
			},
			save: function () {
				return saveDetails( changes() );
			},
		};
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var c = changes();
			if ( ! Object.keys( c ).length ) {
				panelNotice( pi18n.noChanges, 'info' );
				return;
			}
			save.disabled = true;
			saveDetails( c ).then( function () {
				renderDetails();
				panelNotice( pi18n.saved, 'success' );
				var again = panel && panel.body.querySelector( '.bk-panel-actions .button-primary' );
				if ( again ) {
					again.focus();
				}
			}, function () {
				save.disabled = !! ( panel && panel.data.details.locked_by );
			} );
		} );
		panel.body.appendChild( form );
	}

	/**
	 * Phase 6: AI suggestions. The server builds the prompt from the SAVED post; the browser only
	 * names the kind. "Use" fills a field — nothing is saved until Save details — except the
	 * outline's own "Add to the post", which appends (never replaces) and saves.
	 */
	function aiSection( d, inputs, pendingChanges ) {
		var id = panel.id;
		var a = pi18n.ai || {};
		var left = el( 'span', { class: 'bk-panel-help bk-ai-left' } );
		var result = el( 'div', { class: 'bk-ai-result', role: 'region', 'aria-live': 'polite', 'aria-label': a.results } );
		var buttons = el( 'div', { class: 'bk-when-actions bk-ai-buttons' } );
		var kinds = [];
		if ( inputs.title ) {
			kinds.push( [ 'titles', a.titles ] );
		}
		if ( inputs.excerpt ) {
			kinds.push( [ 'excerpt', a.excerpt ] );
		}
		if ( inputs.meta || inputs.excerpt ) {
			kinds.push( [ 'meta', a.meta ] );
		}
		if ( d.supports.editor ) {
			kinds.push( [ 'outline', a.outline ] );
		}

		function setLeft( n ) {
			left.textContent = sprintf( a.left, n );
			Array.prototype.forEach.call( buttons.children, function ( b ) {
				b.disabled = n <= 0;
			} );
		}

		function fill( input, value, label ) {
			input.value = value;
			input.focus();
			speak( sprintf( a.filled, label ) );
			panelNotice( sprintf( a.filled, label ), 'info' );
		}

		function choice( text, onUse, useLabel ) {
			var use = el( 'button', { type: 'button', class: 'button button-small', text: useLabel || a.use } );
			use.addEventListener( 'click', onUse );
			return el( 'li', { class: 'bk-ai-choice' }, [ el( 'span', { class: 'bk-ai-text', text: text } ), use ] );
		}

		function show( kind, list ) {
			result.textContent = '';
			var ul = el( 'ul', { class: 'bk-ai-list' } );
			if ( kind === 'titles' ) {
				list.forEach( function ( s ) {
					ul.appendChild( choice( s, function () {
						fill( inputs.title, s, pi18n.title );
					} ) );
				} );
			} else if ( kind === 'excerpt' ) {
				ul.appendChild( choice( list[ 0 ], function () {
					fill( inputs.excerpt, list[ 0 ], pi18n.excerpt );
				} ) );
			} else if ( kind === 'meta' ) {
				var target = inputs.meta || inputs.excerpt;
				ul.appendChild( choice( list[ 0 ], function () {
					fill( target, list[ 0 ], inputs.meta ? a.metaShort : pi18n.excerpt );
				}, inputs.meta ? a.use : a.useAsExcerpt ) );
			} else {
				list.forEach( function ( s ) {
					ul.appendChild( el( 'li', { class: 'bk-ai-choice' }, [ el( 'span', { class: 'bk-ai-text', text: s } ) ] ) );
				} );
			}
			result.appendChild( el( 'p', { class: 'bk-panel-label', text: a.heading[ kind ] } ) );
			result.appendChild( ul );
			if ( kind === 'outline' ) {
				var add = el( 'button', { type: 'button', class: 'button', text: a.addOutline } );
				add.addEventListener( 'click', function () {
					add.disabled = true;
					// Send any unsaved title/excerpt edits too, so the re-render doesn't drop them.
					var c = pendingChanges();
					c.append_outline = list;
					saveDetails( c ).then( function () {
						renderDetails();
						panelNotice( a.outlineAdded, 'success' );
						speak( a.outlineAdded );
					}, function () {
						add.disabled = false;
					} );
				} );
				result.appendChild( el( 'p', {}, [ add, ' ', el( 'span', { class: 'bk-panel-help', text: a.outlineHelp } ) ] ) );
			}
		}

		kinds.forEach( function ( k ) {
			var b = el( 'button', { type: 'button', class: 'button', text: k[ 1 ] } );
			b.addEventListener( 'click', function () {
				var label = b.textContent;
				Array.prototype.forEach.call( buttons.children, function ( x ) {
					x.disabled = true;
				} );
				b.textContent = a.thinking;
				result.setAttribute( 'aria-busy', 'true' );
				speak( a.thinking );
				apiFetch( { path: base + '/cards/' + id + '/suggest', method: 'POST', data: { kind: k[ 0 ] } } ).then( function ( res ) {
					if ( ! panel || panel.id !== id ) {
						return;
					}
					b.textContent = label;
					result.removeAttribute( 'aria-busy' );
					setLeft( res.remaining );
					panelNotice( '' );
					show( k[ 0 ], res.suggestions );
				}, function ( err ) {
					if ( ! panel || panel.id !== id ) {
						return;
					}
					b.textContent = label;
					result.removeAttribute( 'aria-busy' );
					setLeft( err && err.code === 'bk_ai_limit' ? 0 : d.ai.remaining );
					panelNotice( errorMessage( err ) );
					speak( errorMessage( err ) );
				} );
			} );
			buttons.appendChild( b );
		} );
		setLeft( d.ai.remaining );

		return el( 'div', { class: 'bk-panel-field bk-ai' }, [
			el( 'span', { class: 'bk-panel-label', text: a.label } ),
			el( 'span', { class: 'bk-panel-help', text: a.help } ),
			buttons,
			left,
			result,
		] );
	}

	function drawImage( box ) {
		box.textContent = '';
		var d = panel.data.details;
		var f = d.featured;
		if ( f && safeUrl( f.url ) ) {
			box.appendChild( el( 'img', { class: 'bk-panel-thumb', src: safeUrl( f.url ), alt: f.alt || '' } ) );
		} else {
			box.appendChild( el( 'p', { class: 'bk-panel-help', text: pi18n.noImage } ) );
		}
		if ( ! d.can_upload || ! window.wp.media ) {
			box.appendChild( el( 'p', { class: 'bk-panel-help', text: pi18n.noUpload } ) );
			return;
		}
		if ( d.locked_by ) {
			return;
		}
		var pick = el( 'button', { type: 'button', class: 'button', text: f ? pi18n.replaceImage : pi18n.setImage } );
		var actions = el( 'div', { class: 'bk-when-actions' }, [ pick ] );
		pick.addEventListener( 'click', function () {
			var frame = window.wp.media( { title: pi18n.featured, library: { type: 'image' }, button: { text: pi18n.useImage }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first();
				if ( att && panel ) {
					saveDetails( { featured_media: att.get( 'id' ) } ).then( function () {
						drawImage( box );
					}, function () {} );
				}
			} );
			frame.open();
		} );
		if ( f ) {
			var remove = el( 'button', { type: 'button', class: 'button-link button-link-delete', text: pi18n.removeImage } );
			remove.addEventListener( 'click', function () {
				saveDetails( { featured_media: 0 } ).then( function () {
					drawImage( box );
					var b = box.querySelector( 'button' );
					if ( b ) {
						b.focus();
					}
				}, function () {} );
			} );
			actions.appendChild( remove );
		}
		box.appendChild( actions );
	}

	function renderWrite() {
		var d = panel.data.details;
		var id = panel.id;
		var url = safeUrl( d.editor_url );
		if ( ! url ) {
			return;
		}
		var frame = el( 'iframe', { class: 'bk-panel-frame', src: url, title: sprintf( pi18n.editorTitle, panel.data.card.title ) } );
		frame.addEventListener( 'load', function () {
			var loc = '';
			try {
				loc = frame.contentWindow.location.pathname + frame.contentWindow.location.search;
			} catch ( e ) {
				loc = '';
			}
			// Still this post's editor (a classic save reloads it with the flag kept)? Otherwise —
			// trashed, or a link went elsewhere — close the panel and refresh the board.
			var here = /\/post\.php/.test( loc ) && new RegExp( '[?&]post=' + id + '(&|$)' ).test( loc );
			if ( ! here && panel && panel.id === id && panel.frame === frame ) {
				panel.frame = null;
				closePanel( false );
				showNotice( pi18n.left, 'warning' );
				loadBoard( false );
			}
		} );
		panel.frame = frame;
		panel.body.appendChild( frame );
	}

	// Leaving the page with unsaved work in the panel: let the browser ask.
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirtyState().dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// ---- boot ------------------------------------------------------------------------------

	root.parentNode.insertBefore( buildFilterBar(), root );
	// Visible, not screen-reader-only: the keyboard path has to be discoverable for everyone.
	root.parentNode.insertBefore( el( 'p', { id: 'bk-card-help', class: 'bk-tip', text: i18n.cardHelp } ), root );
	document.body.appendChild( hint );

	/** (Re)load the whole board for the current filters. */
	function loadBoard( announce ) {
		disableDrag();
		cancelPick( false );
		closeMenu( false );
		lanes = {};
		laneOrder = [];
		cards = {};
		root.setAttribute( 'aria-busy', 'true' );
		return apiFetch( { path: base + filterQuery( '?' ) } )
			.then( function ( board ) {
				timezone = board.timezone || '';
				root.textContent = '';
				board.lanes.forEach( function ( lane ) {
					root.appendChild( renderLane( lane ) );
				} );
				root.removeAttribute( 'aria-busy' );
				enableDrag();
				return Promise.all( laneOrder.map( function ( v ) {
					return loadLane( v, false );
				} ) ).then( function () {
					if ( announce ) {
						var total = laneOrder.reduce( function ( n, v ) {
							return n + lanes[ v ].count;
						}, 0 );
						speak( sprintf( i18n.filtered, total ) );
					}
				} );
			} )
			.catch( function ( err ) {
				root.removeAttribute( 'aria-busy' );
				root.textContent = '';
				showNotice( errorMessage( err ) );
				speak( errorMessage( err ) );
			} );
	}

	loadBoard( false );

	// A shared or reloaded link with &card= opens that card's panel.
	var initialCard = parseInt( new URLSearchParams( window.location.search ).get( 'card' ) || '', 10 );
	if ( initialCard > 0 ) {
		openPanel( initialCard );
	}
}() );
