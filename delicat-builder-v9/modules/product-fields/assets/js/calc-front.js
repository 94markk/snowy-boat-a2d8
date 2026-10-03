( function ( $ ) {
	'use strict';

	var DMC_HAS = ( function () {
		try { return !! ( window.CSS && window.CSS.supports && window.CSS.supports( 'selector(:has(*))' ) ); }
		catch ( e ) { return false; }
	} )();

	function stampHasFallback() {
		if ( DMC_HAS ) { return; }
		$( '.dmc-calc' ).each( function () {
			var calc = this, form = $( calc ).closest( 'form.variations_form, form.cart' )[ 0 ];
			var studio = !! calc.querySelector( '.dmc-purchase-studio' ) || calc.classList.contains( 'dmc-purchase-studio' );
			calc.classList.toggle( 'dmc-hasfb-studio', studio );
			calc.classList.toggle( 'dmc-hasfb-trust', !! calc.querySelector( '.dmc-integrated-trust' ) );
			if ( form ) {
				form.classList.toggle( 'dmc-hasfb-studio', studio );
				form.classList.toggle( 'dmc-hasfb-totalcard', !! form.querySelector( '.dmc-plan-total-card' ) );
			}
			$( calc ).closest( '.single_variation_wrap, .woocommerce-variation-add-to-cart, .spb-purchase' )
				.toggleClass( 'dmc-hasfb-studio', studio );
		} );
	}
	stampHasFallback();
	$( document ).on( 'found_variation reset_data dmc:total_updated', stampHasFallback );
	$( '.dmc-calc.dmc-purchase-studio' ).add( $( '.dmc-purchase-studio' ).closest( '.dmc-calc' ) ).each( function () {
		var el = this, cs = window.getComputedStyle( el ), form = $( el ).closest( 'form.variations_form, form.cart' )[ 0 ];
		if ( ! form ) { return; }
		[ '--dmc-section-spacing', '--dmc-quantity-spacing' ].forEach( function ( key ) {
			var value = cs.getPropertyValue( key ); if ( value ) { form.style.setProperty( key, value.trim() ); }
		} );
	} );

	var FUNCS = { abs: 1, ceil: 1, floor: 1, round: 1, sqrt: 1, min: 2, max: 2, pow: 2 };
	var OPS = { 'u-': { p: 5, a: 'r' }, '^': { p: 4, a: 'r' }, '*': { p: 3, a: 'l' }, '/': { p: 3, a: 'l' }, '%': { p: 3, a: 'l' }, '+': { p: 2, a: 'l' }, '-': { p: 2, a: 'l' } };

	function tokenize( expr, vars ) {
		var t = [], i = 0, n = expr.length, prev = null, m;
		while ( i < n ) {
			var ch = expr[ i ];
			if ( /\s/.test( ch ) ) { i++; continue; }
			if ( /[0-9]/.test( ch ) || ( ch === '.' && /[0-9]/.test( expr[ i + 1 ] || '' ) ) ) {
				m = expr.slice( i ).match( /^\d*\.?\d+([eE][+-]?\d+)?/ );
				if ( ! m ) { return null; }
				t.push( [ 'num', parseFloat( m[ 0 ] ) ] ); i += m[ 0 ].length; prev = 'num'; continue;
			}
			if ( ch === '{' ) {
				m = expr.slice( i ).match( /^\{([A-Za-z0-9_]+)\}/ );
				if ( ! m ) { return null; }
				t.push( [ 'num', parseFloat( vars[ m[ 1 ] ] ) || 0 ] ); i += m[ 0 ].length; prev = 'num'; continue;
			}
			if ( /[A-Za-z_]/.test( ch ) ) {
				m = expr.slice( i ).match( /^[A-Za-z_][A-Za-z0-9_]*/ );
				var word = m[ 0 ], lower = word.toLowerCase(), j = i + word.length;
				while ( j < n && /\s/.test( expr[ j ] ) ) { j++; }
				if ( expr[ j ] === '(' ) {
					if ( ! FUNCS[ lower ] ) { return null; }
					t.push( [ 'func', lower ] ); i += word.length; prev = 'func'; continue;
				}
				t.push( [ 'num', parseFloat( vars[ word ] ) || 0 ] ); i += word.length; prev = 'num'; continue;
			}
			if ( ch === '(' ) { t.push( [ 'lp' ] ); i++; prev = 'lp'; continue; }
			if ( ch === ')' ) { t.push( [ 'rp' ] ); i++; prev = 'rp'; continue; }
			if ( ch === ',' ) { t.push( [ 'comma' ] ); i++; prev = 'comma'; continue; }
			if ( '+-*/%^'.indexOf( ch ) > -1 ) {
				if ( ch === '-' && ( prev === null || prev === 'op' || prev === 'lp' || prev === 'comma' ) ) { t.push( [ 'op', 'u-' ] ); }
				else if ( ch === '+' && ( prev === null || prev === 'op' || prev === 'lp' || prev === 'comma' ) ) {  }
				else { t.push( [ 'op', ch ] ); }
				i++; prev = 'op'; continue;
			}
			return null;
		}
		return t;
	}

	function toRpn( tokens ) {
		var out = [], st = [];
		for ( var k = 0; k < tokens.length; k++ ) {
			var tk = tokens[ k ];
			if ( tk[ 0 ] === 'num' ) { out.push( tk ); }
			else if ( tk[ 0 ] === 'func' ) { st.push( tk ); }
			else if ( tk[ 0 ] === 'comma' ) { while ( st.length && st[ st.length - 1 ][ 0 ] !== 'lp' ) { out.push( st.pop() ); } if ( ! st.length ) { return null; } }
			else if ( tk[ 0 ] === 'op' ) {
				var o1 = OPS[ tk[ 1 ] ];
				while ( st.length && st[ st.length - 1 ][ 0 ] === 'op' ) {
					var o2 = OPS[ st[ st.length - 1 ][ 1 ] ];
					if ( ( o1.a === 'l' && o1.p <= o2.p ) || ( o1.a === 'r' && o1.p < o2.p ) ) { out.push( st.pop() ); } else { break; }
				}
				st.push( tk );
			} else if ( tk[ 0 ] === 'lp' ) { st.push( tk ); }
			else if ( tk[ 0 ] === 'rp' ) {
				while ( st.length && st[ st.length - 1 ][ 0 ] !== 'lp' ) { out.push( st.pop() ); }
				if ( ! st.length ) { return null; }
				st.pop();
				if ( st.length && st[ st.length - 1 ][ 0 ] === 'func' ) { out.push( st.pop() ); }
			}
		}
		while ( st.length ) { var top = st.pop(); if ( top[ 0 ] === 'lp' || top[ 0 ] === 'rp' ) { return null; } out.push( top ); }
		return out;
	}

	function evalRpn( rpn ) {
		var s = [];
		for ( var k = 0; k < rpn.length; k++ ) {
			var t = rpn[ k ];
			if ( t[ 0 ] === 'num' ) { s.push( t[ 1 ] ); continue; }
			if ( t[ 0 ] === 'op' ) {
				if ( t[ 1 ] === 'u-' ) { if ( ! s.length ) { return 0; } s.push( -s.pop() ); continue; }
				if ( s.length < 2 ) { return 0; }
				var b = s.pop(), a = s.pop();
				if ( t[ 1 ] === '+' ) { s.push( a + b ); } else if ( t[ 1 ] === '-' ) { s.push( a - b ); }
				else if ( t[ 1 ] === '*' ) { s.push( a * b ); } else if ( t[ 1 ] === '/' ) { s.push( b === 0 ? 0 : a / b ); }
				else if ( t[ 1 ] === '%' ) { s.push( b === 0 ? 0 : a % b ); } else if ( t[ 1 ] === '^' ) { s.push( Math.pow( a, b ) ); }
				continue;
			}
			if ( t[ 0 ] === 'func' ) {
				var nargs = FUNCS[ t[ 1 ] ];
				if ( s.length < nargs ) { return 0; }
				var args = s.splice( s.length - nargs, nargs );
				switch ( t[ 1 ] ) {
					case 'abs': s.push( Math.abs( args[ 0 ] ) ); break;
					case 'ceil': s.push( Math.ceil( args[ 0 ] ) ); break;
					case 'floor': s.push( Math.floor( args[ 0 ] ) ); break;
					case 'round': s.push( Math.round( args[ 0 ] ) ); break;
					case 'sqrt': s.push( args[ 0 ] >= 0 ? Math.sqrt( args[ 0 ] ) : 0 ); break;
					case 'min': s.push( Math.min( args[ 0 ], args[ 1 ] ) ); break;
					case 'max': s.push( Math.max( args[ 0 ], args[ 1 ] ) ); break;
					case 'pow': s.push( Math.pow( args[ 0 ], args[ 1 ] ) ); break;
				}
			}
		}
		return s.length === 1 ? s[ 0 ] : 0;
	}

	function evalExpr( expr, vars ) {
		if ( ! expr ) { return null; }
		var tk = tokenize( expr, vars ); if ( ! tk ) { return null; }
		var rpn = toRpn( tk ); if ( ! rpn ) { return null; }
		var r = evalRpn( rpn ); return isFinite( r ) ? r : null;
	}

	function formatPrice( amount ) {
		var d = ( DMCCalc && DMCCalc.decimals != null ) ? DMCCalc.decimals : 2;
		var sym = ( DMCCalc && DMCCalc.symbol ) || '';
		var pos = ( DMCCalc && DMCCalc.position ) || 'left';
		var num = amount.toFixed( d );
		switch ( pos ) {
			case 'right': return num + sym;
			case 'left_space': return sym + ' ' + num;
			case 'right_space': return num + ' ' + sym;
			default: return sym + num;
		}
	}

	function unitPrice( amount, base, ptype ) {
		amount = parseFloat( amount ) || 0;
		return ptype === 'percent' ? ( base * amount / 100 ) : amount;
	}

	function initCalc( $calc ) {
		if ( $calc.attr( 'data-dmc-initialized' ) === '1' ) { return; }
		var cfg;
		try { cfg = JSON.parse( $calc.attr( 'data-config' ) ); } catch ( e ) { return; }
		$calc.attr( 'data-dmc-initialized', '1' );
		var defaultBase = parseFloat( $calc.attr( 'data-base' ) ) || 0;
		var base = defaultBase;
		var rate = parseFloat( $calc.attr( 'data-rate' ) ) || 1;
		var customRate = parseFloat( $calc.attr( 'data-customrate' ) );
		if ( isNaN( customRate ) ) { customRate = 1; }

		function fieldEls( id ) { return $calc.find( '[data-field="' + id + '"]' ); }

		function optionPrice( f, value ) {
			var p = 0;
			( f.options || [] ).forEach( function ( o ) { if ( String( o.value ) === String( value ) ) { p = o.price; } } );
			return p;
		}

		function rawValue( f ) {
			var $f = fieldEls( f.id );
			if ( f.type === 'toggle' ) { return $f.find( 'input:checked' ).length ? 'yes' : ''; }
			if ( f.type === 'checkbox' ) {
				var arr = []; $f.find( 'input:checked' ).each( function () { arr.push( this.value ); } ); return arr;
			}
			if ( f.type === 'radio' ) { var r = $f.find( 'input:checked' ); return r.length ? r.val() : ''; }
			var v = $f.find( '.dmc-input' ).val();
			return ( v === undefined || v === null ) ? '' : v;
		}

		function compare( a, op, b ) {
			if ( Array.isArray( a ) ) {
				var has = a.map( String ).indexOf( String( b ) ) > -1;
				return ( op === '!=' || op === 'not_contains' ) ? ! has : has;
			}
			var na = parseFloat( a ), nb = parseFloat( b );
			if ( ! isNaN( na ) && ! isNaN( nb ) && a !== '' && b !== '' ) {
				switch ( op ) {
					case '>': return na > nb; case '<': return na < nb;
					case '>=': return na >= nb; case '<=': return na <= nb;
					case '!=': return na !== nb; default: return na === nb;
				}
			}
			var sa = String( a ), sb = String( b );
			if ( op === 'contains' ) { return sb === '' ? true : sa.indexOf( sb ) > -1; }
			if ( op === 'not_contains' ) { return sb === '' ? false : sa.indexOf( sb ) === -1; }
			return op === '!=' ? sa !== sb : sa === sb;
		}

		function activeMap( values ) {
			var active = {};
			cfg.fields.forEach( function ( f ) { active[ f.id ] = true; } );
			for ( var p = 0; p < cfg.fields.length; p++ ) {
				var changed = false;
				cfg.fields.forEach( function ( f ) {
					var ok = true;
					if ( f.conditions && f.conditions.length ) {
						var res = f.conditions.map( function ( c ) {
							var refActive = active[ c.field ] !== false;
							var v = ( refActive && values[ c.field ] !== undefined ) ? values[ c.field ] : '';
							return compare( v, c.op, c.value );
						} );
						ok = ( f.condition_logic === 'any' ) ? res.indexOf( true ) > -1 : res.indexOf( false ) === -1;
					}
					if ( active[ f.id ] !== ok ) { active[ f.id ] = ok; changed = true; }
				} );
				if ( ! changed ) { break; }
			}
			return active;
		}

		function contribution( f, values, active ) {
			if ( ! active[ f.id ] ) { return 0; }
			var ptype = f.price_type === 'percent' ? 'percent' : 'fixed';
			var val = values[ f.id ];
			if ( f.type === 'number' || f.type === 'range' ) {
				var num = parseFloat( val ); if ( isNaN( num ) ) { num = 0; }
				if ( f.min !== '' && f.min != null ) { num = Math.max( parseFloat( f.min ), num ); }
				if ( f.max !== '' && f.max != null ) { num = Math.min( parseFloat( f.max ), num ); }
				return num * unitPrice( f.price, base, ptype );
			}
			if ( f.type === 'toggle' ) { return val === 'yes' ? unitPrice( f.price, base, ptype ) : 0; }
			if ( f.type === 'select' || f.type === 'radio' ) { return val ? unitPrice( optionPrice( f, val ), base, ptype ) : 0; }
			if ( f.type === 'checkbox' ) {
				var sum = 0; ( val || [] ).forEach( function ( v ) { sum += unitPrice( optionPrice( f, v ), base, ptype ); } ); return sum;
			}
			if ( f.type === 'hidden' ) { return unitPrice( f.price, base, ptype ); }
			return 0;
		}

		function formulaValue( f, values ) {
			var v = values[ f.id ];
			if ( f.type === 'number' || f.type === 'range' ) {
				var num = parseFloat( v ); if ( isNaN( num ) ) { num = 0; }
				if ( f.min !== '' && f.min != null ) { num = Math.max( parseFloat( f.min ), num ); }
				if ( f.max !== '' && f.max != null ) { num = Math.min( parseFloat( f.max ), num ); }
				return num;
			}
			if ( f.type === 'toggle' ) { return v === 'yes' ? 1 : 0; }
			if ( f.type === 'select' || f.type === 'radio' ) { return v ? ( parseFloat( optionPrice( f, v ) ) || 0 ) : 0; }
			if ( f.type === 'checkbox' ) { var s = 0; ( v || [] ).forEach( function ( x ) { s += parseFloat( optionPrice( f, x ) ) || 0; } ); return s; }
			if ( f.type === 'hidden' ) { var d = parseFloat( f.default ); return isNaN( d ) ? ( parseFloat( f.price ) || 0 ) : d; }
			return 0;
		}

		function recalc() {
			var values = {};
			cfg.fields.forEach( function ( f ) { values[ f.id ] = rawValue( f ); } );
			var active = activeMap( values );

			cfg.fields.forEach( function ( f ) {
				var $field = fieldEls( f.id ), isActive = !! active[ f.id ];
				$field.toggle( isActive ).attr( 'aria-hidden', isActive ? 'false' : 'true' );

				$field.find( ':input' ).prop( 'disabled', ! isActive );
			} );

			$calc.find( '.dmc-opt' ).each( function () {
				$( this ).toggleClass( 'dmc-selected', $( this ).find( 'input' ).is( ':checked' ) );
			} );

			var vars = { base: base, rate: customRate }, sum = 0;
			cfg.fields.forEach( function ( f ) {
				var on = !! active[ f.id ];
				vars[ f.id ] = on ? formulaValue( f, values ) : 0;
				sum += on ? contribution( f, values, active ) : 0;
			} );

			var totalBase;
			if ( cfg.formula && cfg.formula.trim() ) {
				totalBase = evalExpr( cfg.formula, vars );

				if ( totalBase === null ) { totalBase = base + sum; }
			} else {
				totalBase = base + sum;
			}
			if ( ! isFinite( totalBase ) || totalBase < 0 ) { totalBase = base + sum; }

			var qty = 1;
			var $productForm = $calc.closest( 'form.cart' );
			var $qty = $productForm.find( 'input.qty' ).first();
			if ( $qty.length ) { qty = parseFloat( $qty.val() ); }
			if ( ! isFinite( qty ) || qty <= 0 ) { qty = 1; }
			var $total = $calc.find( '.dmc-calc-total-value' );
			var variationInput = $productForm.find( 'input.variation_id, input[name=variation_id]' ).first();
			var variationRequired = $productForm.hasClass( 'variations_form' ) || variationInput.length > 0;
			var variationReady = ! variationRequired || ( parseInt( variationInput.val() || 0, 10 ) > 0 );

			var unit = Math.round( totalBase * rate * 100 ) / 100;
			var nextText = variationReady ? formatPrice( unit * qty ) : '—';
			$total.toggleClass( 'is-pending', ! variationReady );
			if ( $total.text() !== nextText ) {
				$total.text( nextText ).removeClass( 'dmc-total-pulse' );
				void $total[0].offsetWidth;
				$total.addClass( 'dmc-total-pulse' );
			}
			$calc.attr( 'data-current-quantity', qty );

			var totalDetail = {
				productId: parseInt( $calc.attr( 'data-product-id' ) || $productForm.find( '[name=add-to-cart]' ).val() || 0, 10 ) || 0,
				base: totalBase,
				rate: rate,
				quantity: qty,
				total: variationReady ? ( unit * qty ) : 0,
				formatted: nextText,
				ready: variationReady
			};
			try { $productForm.get( 0 ).dispatchEvent( new CustomEvent( 'dmc:total_updated', { bubbles: true, detail: totalDetail } ) ); } catch ( err ) {}
			try { $productForm.get( 0 ).dispatchEvent( new CustomEvent( 'dmc:quantity_synced', { bubbles: true, detail: { quantity: qty, owner: 'woocommerce', source: 'dmc-calculator' } } ) ); } catch ( err2 ) {}
		}

		var recalcFrame = 0;
		function scheduleRecalc() {
			if ( recalcFrame ) { return; }
			var raf = window.requestAnimationFrame || function ( callback ) { return window.setTimeout( callback, 16 ); };
			recalcFrame = raf( function () {
				recalcFrame = 0;
				recalc();
			} );
		}

		$calc.on( 'input change', '.dmc-input', scheduleRecalc );
		var $cartForm = $calc.closest( 'form.cart' );
		$cartForm.addClass( 'dmc-calculator-ready' );
		$cartForm.attr( 'data-dmc-quantity-owner', 'woocommerce' );
		$cartForm.on( 'input change', 'input.qty', scheduleRecalc );
		$cartForm.on( 'click', '.plus,.minus,[data-quantity-action],.quantity button', function () { window.setTimeout( scheduleRecalc, 0 ); });

		function cleanText( html ) {
			var div = document.createElement( 'div' );
			div.innerHTML = html || '';
			return ( div.textContent || div.innerText || '' ).replace( /\s+/g, ' ' ).trim();
		}

		function variationPlanName( variation ) {
			if ( variation && variation.variation_name ) { return String( variation.variation_name ).trim(); }
			var id = variation && parseInt( variation.variation_id, 10 );
			var $root = $calc.closest( 'form.variations_form' );
			var $card = id ? $root.find( '.ddsw-card[data-variation-id="' + id + '"]' ).first() : $();
			var cardName = $card.find( '.ddsw-title' ).first().text().trim();
			if ( cardName ) { return cardName; }
			if ( variation && variation.attributes ) {
				var names = [];
				Object.keys( variation.attributes ).forEach( function ( key ) {
					var val = String( variation.attributes[ key ] || '' ).replace( /[-_]+/g, ' ' ).trim();
					if ( val ) { names.push( val ); }
				} );
				if ( names.length ) { return names.join( ' · ' ); }
			}
			var desc = cleanText( variation && variation.variation_description );
			return desc || 'Plan sélectionné';
		}

		function enhancePurchaseStudio() {
			var $card = $calc.find( '.dmc-purchase-studio' ).first();
			if ( ! $card.length ) { return; }
			var $formRoot = $calc.closest( 'form.cart' );

			$formRoot.find( '.dmc-purchase-subtotal, .dmc-studio-subtotal, .dmc-studio-trust' ).remove();
			function hideLegacyTrust() {

				$formRoot.find( '.ddsw-trust-strip, .ddsw-selection-hero' ).each( function () {
					if ( this.hidden && this.classList.contains( 'dmc-trust-source-hidden' ) ) { return; }
					this.hidden = true;
					this.setAttribute( 'aria-hidden', 'true' );
					this.classList.add( 'dmc-trust-source-hidden' );
					this.style.setProperty( 'display', 'none', 'important' );
				} );
			}
			hideLegacyTrust();

		}

		function setCardState( state ) {
			var $card = $calc.find( '.dmc-plan-total-card' ).first();
			if ( ! $card.length ) { return; }
			$card.removeClass( 'is-empty is-checking is-selected is-unavailable' ).addClass( state );
		}

		function updateWooSummary( variation ) {
			var $summary = $calc.find( '[data-dmc-plan-summary]' ).first();
			if ( ! $summary.length ) { return; }
			var name = variationPlanName( variation );
			var inStock = ! variation || variation.is_in_stock !== false;
			var availability = cleanText( variation && variation.availability_html );
			var status = availability || ( inStock ? 'Disponible' : 'Rupture de stock' );
			setCardState( inStock ? 'is-selected' : 'is-unavailable' );
			$summary.prop( 'hidden', false ).removeAttr( 'hidden' );
			$summary.find( '[data-dmc-plan-name]' ).text( name );
			$summary.find( '[data-dmc-plan-status]' )
				.text( status )
				.removeClass( 'is-awaiting-selection' )
				.toggleClass( 'is-in-stock', inStock )
				.toggleClass( 'is-out-of-stock', ! inStock );
			var $card = $summary.closest( '.dmc-plan-total-card' );
			$card.removeClass( 'dmc-summary-updated' );
			if ( $card.length ) { void $card[0].offsetWidth; $card.addClass( 'dmc-summary-updated' ); }
		}

		function previewSelectedPlan( detail ) {
			var $summary = $calc.find( '[data-dmc-plan-summary]' ).first();
			if ( ! $summary.length || ! detail ) { return; }
			var name = String( detail.name || 'Plan sélectionné' ).trim();
			setCardState( 'is-checking' );
			$summary.find( '[data-dmc-plan-name]' ).text( name || 'Plan sélectionné' );
			$summary.find( '[data-dmc-plan-status]' )
				.text( 'Vérification…' )
				.removeClass( 'is-in-stock is-out-of-stock' )
				.addClass( 'is-awaiting-selection' );
			$calc.find( '.dmc-calc-total-value' ).text( '—' ).addClass( 'is-pending' );
		}

		function selectedCardDetail() {
			var $root = $calc.closest( 'form.variations_form' );
			if ( ! $root.length ) { return null; }
			var $card = $root.find( '.ddsw-card.is-selected,[aria-checked="true"].ddsw-card,[aria-pressed="true"].ddsw-card' ).first();
			if ( ! $card.length ) { return null; }
			return {
				variationId: parseInt( $card.attr( 'data-variation-id' ) || 0, 10 ) || 0,
				name: String( $card.attr( 'data-variation-name' ) || $card.find( '.ddsw-title' ).first().text() || '' ).trim(),
				displayPrice: parseFloat( $card.attr( 'data-display-price' ) || 0 ) || 0
			};
		}

		function syncSelectionFallback() {
			var $summary = $calc.find( '[data-dmc-plan-summary]' ).first();
			if ( ! $summary.length ) { return; }
			var detail = selectedCardDetail();
			if ( ! detail ) { return; }
			var $variationInput = $calc.closest( 'form.cart' ).find( 'input.variation_id, input[name=variation_id]' ).first();
			var ready = parseInt( $variationInput.val() || 0, 10 ) > 0;
			if ( ready ) {
				setCardState( 'is-selected' );
				$summary.find( '[data-dmc-plan-name]' ).text( detail.name || 'Plan sélectionné' );
				$summary.find( '[data-dmc-plan-status]' )
					.text( 'Disponible' )
					.removeClass( 'is-awaiting-selection is-out-of-stock' )
					.addClass( 'is-in-stock' );
			} else {
				previewSelectedPlan( detail );
			}
		}

		var $form = $calc.closest( 'form.variations_form' );
		if ( $form.length ) {
			$form.on( 'dsb:plan_selected.dmcCalc', function ( e, detail ) { previewSelectedPlan( detail || ( e.originalEvent && e.originalEvent.detail ) || {} ); } );
			$form.on( 'click.dmcCalc', '.ddsw-card', function () {
				var card = this;
				window.setTimeout( function () {
					previewSelectedPlan( { variationId: parseInt( card.getAttribute( 'data-variation-id' ) || 0, 10 ) || 0, name: String( card.getAttribute( 'data-variation-name' ) || '' ).trim(), displayPrice: parseFloat( card.getAttribute( 'data-display-price' ) || 0 ) || 0 } );
				}, 0 );
			} );
			$form.on( 'found_variation show_variation', function ( e, variation ) {
				if ( variation && variation.display_price != null ) {
					base = ( parseFloat( variation.display_price ) || 0 ) / ( rate || 1 );
				}
				updateWooSummary( variation || {} );
				recalc();
			} );
			$form.on( 'reset_data hide_variation', function () {
				base = defaultBase;
				var $summary = $calc.find( '[data-dmc-plan-summary]' ).first();
				setCardState( 'is-empty' );
				$summary.find( '[data-dmc-plan-name]' ).text( 'Choisissez un plan' );
				$summary.find( '[data-dmc-plan-status]' ).text( 'Sélection requise' ).removeClass( 'is-in-stock is-out-of-stock' ).addClass( 'is-awaiting-selection' );
				window.setTimeout( syncSelectionFallback, 0 );
				recalc();
			} );
			$form.on( 'change.dmcCalc input.dmcCalc', 'select[name^="attribute_"], input[name="variation_id"], input.variation_id', function () { window.setTimeout( syncSelectionFallback, 0 ); } );
		}

		enhancePurchaseStudio();
		recalc();
	}

	function bootCalculators() {
		stampHasFallback();
		$( '.dmc-calc' ).each( function () { initCalc( $( this ) ); } );
	}
	$( bootCalculators );
	document.addEventListener( 'dsb:content-updated', bootCalculators );
	$( document.body ).on( 'updated_wc_div.dmcCalc wc_fragments_loaded.dmcCalc', bootCalculators );

	$( document ).on( 'click', '.dmc-help-trigger', function ( e ) {
		e.preventDefault(); e.stopPropagation();
		var data = {}; try { data = JSON.parse( $( this ).attr( 'data-help' ) || '{}' ); } catch ( err ) {}
		var $modal = $( this ).closest( 'form, .product, body' ).find( '.dmc-help-modal' ).first();
		if ( ! $modal.length ) { $modal = $( '.dmc-help-modal' ).first(); }

		var $media = $modal.find( '.dmc-help-media' ).empty(), node = null;
		if ( data.video ) {
			node = document.createElement( 'video' );
			node.controls = true; node.playsInline = true; node.preload = 'metadata';
			node.src = String( data.video );
		} else if ( data.image ) {
			node = document.createElement( 'img' );
			node.loading = 'eager'; node.decoding = 'async'; node.alt = '';
			node.src = String( data.image );
		}
		if ( node ) { $media.append( node ); }
		$media.toggle( !! node );
		$modal.find( '.dmc-help-title' ).text( data.title || 'Aide' );
		$modal.find( '.dmc-help-copy' ).text( data.text || '' ).toggle( !! data.text );
		$modal.addClass( 'is-open' ).attr( 'aria-hidden', 'false' ).data( 'return-focus', this );
		$( 'body' ).addClass( 'dmc-modal-open' );
		setTimeout( function () { $modal.find( '.dmc-help-close' ).trigger( 'focus' ); }, 20 );
	} );
	function closeHelp( $modal ) {
		if ( ! $modal || ! $modal.length ) { return; }
		$modal.removeClass( 'is-open' ).attr( 'aria-hidden', 'true' );
		$modal.find( 'video' ).each( function () { try { this.pause(); } catch ( e ) {} } );
		$( 'body' ).removeClass( 'dmc-modal-open' );
		var el = $modal.data( 'return-focus' ); if ( el && el.focus ) { el.focus(); }
	}
	$( document ).on( 'click', '.dmc-help-close,.dmc-help-backdrop', function () { closeHelp( $( this ).closest( '.dmc-help-modal' ) ); } );
	$( document ).on( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeHelp( $( '.dmc-help-modal.is-open' ).last() ); } } );

} )( jQuery );

document.addEventListener('input', function(e){var el=e.target;if(!el||!el.classList||!el.classList.contains('dmc-input'))return;var mode=el.getAttribute('data-transform');if(!mode||mode==='none')return;var value=el.value||'';if(mode==='digits')value=value.replace(/\D+/g,'');else if(mode==='uppercase')value=value.toUpperCase();else if(mode==='lowercase')value=value.toLowerCase();if(el.value!==value)el.value=value;});
document.addEventListener('invalid',function(e){var el=e.target;if(!el||!el.getAttribute)return;var msg=el.getAttribute('data-error');if(msg)el.setCustomValidity(msg);},true);
document.addEventListener('input',function(e){if(e.target&&e.target.setCustomValidity)e.target.setCustomValidity('');},true);
