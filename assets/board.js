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
			class: 'bk-card',
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
			items.push( { label: i18n.open, href: safeUrl( card.edit_url ) } );
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
}() );
