/* global jQuery, DMCBuilder */
( function ( $ ) {
	'use strict';

	var TYPES = ( DMCBuilder && DMCBuilder.types ) || [ 'number', 'range', 'select', 'radio', 'checkbox', 'toggle', 'text', 'textarea', 'email', 'tel', 'password', 'url', 'date', 'hidden', 'heading', 'content' ];
	var APPS = ( DMCBuilder && DMCBuilder.appearances ) || [ 'default', 'buttons', 'color', 'image', 'cards' ];
	var PTYPES = ( DMCBuilder && DMCBuilder.priceTypes ) || [ 'fixed', 'percent' ];
	var OPS = ( DMCBuilder && DMCBuilder.ops ) || [ '==', '!=', '>', '<', '>=', '<=', 'contains', 'not_contains' ];

	var $root = $( '#dmc-builder-fields' );
	var $store = $( '#dmc-calc-config' );
	if ( ! $root.length ) { return; }

	var state = { fields: [], design: {} };
	var designDefaults = { preset:'modern', card_radius:24, field_radius:18, field_height:58, gap:14, section_spacing:18, quantity_spacing:18, card_padding:14, summary_padding:16, card_bg:'#ffffff', field_bg:'#ffffff', text:'#111827', border:'#dbe2ea', accent:'#d9a441', help_bg:'#ffd43b', shadow:true, floating_labels:false, show_total:true, total_label:'Total à payer', total_style:'card', total_radius:18, total_bg:'#ffffff', help_glow:true, purchase_studio:true, show_product_name:true, show_trust_chips:false, animate_total:true };
	try {
		var parsed = JSON.parse( $store.val() );
		if ( parsed && Array.isArray( parsed.fields ) ) { state.fields = parsed.fields; state.design = $.extend( {}, designDefaults, parsed.design || {} ); }
	} catch ( e ) {}
	state.design = $.extend( {}, designDefaults, state.design || {} );

	function uid() { return 'f' + Math.random().toString( 36 ).slice( 2, 8 ); }
	function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }
	function isChoice( t ) { return t === 'select' || t === 'radio' || t === 'checkbox'; }
	function isNumeric( t ) { return t === 'number' || t === 'range'; }
	function isData( t ) { return t === 'text' || t === 'textarea' || t === 'email' || t === 'url' || t === 'date'; }

	function sel( name, list, current ) {
		return '<select class="' + name + '">' + list.map( function ( o ) {
			return '<option value="' + o + '"' + ( o === current ? ' selected' : '' ) + '>' + o + '</option>';
		} ).join( '' ) + '</select>';
	}

	function priceType( current ) { return sel( 'dmc-f-ptype', PTYPES, current || 'fixed' ); }

	function optionRow( opt, appearance ) {
		opt = opt || { label: '', value: '', price: 0, color: '', image: '', desc: '' };
		var extra = '';
		if ( appearance === 'color' ) {
			extra = '<input type="text" class="dmc-o-color" placeholder="#hex" value="' + esc( opt.color ) + '">';
		} else if ( appearance === 'image' || appearance === 'cards' ) {
			extra = '<input type="text" class="dmc-o-image" placeholder="image URL" value="' + esc( opt.image ) + '">';
		}
		if ( appearance === 'cards' ) {
			extra += '<input type="text" class="dmc-o-desc" placeholder="card text" value="' + esc( opt.desc ) + '">';
		}
		return '<div class="dmc-b-opt">' +
			'<input type="text" class="dmc-o-label" placeholder="Label" value="' + esc( opt.label ) + '">' +
			'<input type="text" class="dmc-o-value" placeholder="value" value="' + esc( opt.value ) + '">' +
			'<input type="number" step="any" class="dmc-o-price" placeholder="price" value="' + esc( opt.price ) + '">' +
			extra +
			'<button type="button" class="button-link dmc-o-remove">&times;</button>' +
		'</div>';
	}

	function conditionRow( cond, others ) {
		cond = cond || { field: '', op: '==', value: '' };
		var fopts = others.map( function ( f ) {
			return '<option value="' + esc( f.id ) + '"' + ( f.id === cond.field ? ' selected' : '' ) + '>' + esc( f.label || f.id ) + '</option>';
		} ).join( '' );
		var oopts = OPS.map( function ( o ) {
			return '<option value="' + o + '"' + ( o === cond.op ? ' selected' : '' ) + '>' + o + '</option>';
		} ).join( '' );
		return '<div class="dmc-b-cond">' +
			'<select class="dmc-c-field"><option value="">—</option>' + fopts + '</select>' +
			'<select class="dmc-c-op">' + oopts + '</select>' +
			'<input type="text" class="dmc-c-value" placeholder="value" value="' + esc( cond.value ) + '">' +
			'<button type="button" class="button-link dmc-c-remove">&times;</button>' +
		'</div>';
	}

	function bodyFor( f ) {
		var t = f.type, html = '';

		if ( isNumeric( t ) ) {
			html += '<div class="dmc-row-inline">' +
				'<label>min <input type="number" step="any" class="dmc-f-min" value="' + esc( f.min ) + '"></label>' +
				'<label>max <input type="number" step="any" class="dmc-f-max" value="' + esc( f.max ) + '"></label>' +
				'<label>step <input type="number" step="any" class="dmc-f-step" value="' + esc( f.step ) + '"></label>' +
				'<label>price/unit <input type="number" step="any" class="dmc-f-price" value="' + esc( f.price != null ? f.price : 0 ) + '"></label>' +
				'<label>type ' + priceType( f.price_type ) + '</label>' +
				'<label>suffix <input type="text" class="dmc-f-suffix" value="' + esc( f.suffix ) + '" placeholder="USD"></label>' +
			'</div>';
		} else if ( t === 'toggle' ) {
			html += '<div class="dmc-row-inline">' +
				'<label>on-text <input type="text" class="dmc-f-toggletext" value="' + esc( f.toggle_text ) + '"></label>' +
				'<label>price <input type="number" step="any" class="dmc-f-price" value="' + esc( f.price != null ? f.price : 0 ) + '"></label>' +
				'<label>type ' + priceType( f.price_type ) + '</label>' +
			'</div>';
		} else if ( t === 'hidden' ) {
			html += '<div class="dmc-row-inline">' +
				'<label>value <input type="text" class="dmc-f-default" value="' + esc( f.default ) + '"></label>' +
				'<label>price <input type="number" step="any" class="dmc-f-price" value="' + esc( f.price != null ? f.price : 0 ) + '"></label>' +
				'<label>type ' + priceType( f.price_type ) + '</label>' +
			'</div>';
		} else if ( isChoice( t ) ) {
			var app = f.appearance || 'default';
			var optsHtml = ( f.options || [] ).map( function ( o ) { return optionRow( o, app ); } ).join( '' );
			html += '<div class="dmc-row-inline">' +
				'<label>display ' + sel( 'dmc-f-appearance', APPS, app ) + '</label>' +
				'<label>price type ' + priceType( f.price_type ) + '</label>' +
			'</div>' +
			'<div class="dmc-f-options"><strong>Options</strong> <span class="description">(value = identifier; price added when chosen)</span>' +
				'<div class="dmc-f-opts">' + optsHtml + '</div>' +
				'<button type="button" class="button dmc-o-add">+ option</button>' +
			'</div>';
		} else if ( isData( t ) ) {
			html += '<div class="dmc-row-inline"><label>default <input type="text" class="dmc-f-default" value="' + esc( f.default ) + '"></label></div>';
		} else if ( t === 'content' ) {
			html += '<div><strong>Content (HTML allowed)</strong><br><textarea class="dmc-f-content widefat" rows="3">' + esc( f.content ) + '</textarea></div>';
		}
		// heading: nothing extra (label = heading text, description = subtext)

		if ( t !== 'content' ) {
			var descLabel = ( t === 'heading' ) ? 'subtext' : 'description';
			html += '<label class="dmc-f-descwrap">' + descLabel + ' <input type="text" class="dmc-f-desc" value="' + esc( f.description ) + '"></label>';
		}

		if ( t !== 'heading' && t !== 'content' && t !== 'hidden' ) {
			html += '<div class="dmc-help-builder"><strong>❓ Aide client & apparence du champ</strong>' +
				'<div class="dmc-row-inline">' +
				'<label>Placeholder <input type="text" class="dmc-f-placeholder" value="' + esc( f.placeholder ) + '" placeholder="Ex: Entrez votre Client ID"></label>' +
				'<label>Icône champ <input type="text" class="dmc-f-icon" value="' + esc( f.icon ) + '" placeholder="👤"></label>' +
				'<label><input type="checkbox" class="dmc-f-help-enabled"' + ( f.help_enabled ? ' checked' : '' ) + '> Activer ?</label>' +
				'<label>Icône aide <input type="text" class="dmc-f-help-icon" value="' + esc( f.help_icon || '?' ) + '" placeholder="?"></label>' +
				'</div>' +
				'<div class="dmc-row-inline">' +
				'<label>Titre popup <input type="text" class="dmc-f-help-title" value="' + esc( f.help_title ) + '"></label>' +
				'<label>Image URL <span class="dmc-media-line"><input type="url" class="dmc-f-help-image" value="' + esc( f.help_image ) + '"><button type="button" class="button dmc-media-pick">Choisir</button></span></label>' +
				'<label>Vidéo URL <input type="url" class="dmc-f-help-video" value="' + esc( f.help_video ) + '"></label>' +
				'</div>' +
				'<label class="dmc-f-descwrap">Texte d’aide <textarea class="dmc-f-help-text widefat" rows="2">' + esc( f.help_text ) + '</textarea></label>' +
			'</div>' +
			'<div class="dmc-validation-builder"><strong>✅ Validation intelligente</strong>' +
				'<div class="dmc-row-inline">' +
				'<label>Longueur min <input type="number" min="0" max="500" class="dmc-f-minlength" value="' + esc( f.min_length || '' ) + '"></label>' +
				'<label>Longueur max <input type="number" min="0" max="500" class="dmc-f-maxlength" value="' + esc( f.max_length || '' ) + '"></label>' +
				'<label>Format <select class="dmc-f-transform"><option value="none">Aucun</option><option value="uppercase"' + (f.transform==='uppercase'?' selected':'') + '>MAJUSCULES</option><option value="lowercase"' + (f.transform==='lowercase'?' selected':'') + '>minuscules</option><option value="digits"' + (f.transform==='digits'?' selected':'') + '>Chiffres seulement</option></select></label>' +
				'</div>' +
				'<label class="dmc-f-descwrap">Regex / pattern <input type="text" class="dmc-f-pattern" value="' + esc( f.pattern || '' ) + '" placeholder="Ex: [0-9]{9}"></label>' +
				'<label class="dmc-f-descwrap">Message d’erreur personnalisé <input type="text" class="dmc-f-error" value="' + esc( f.error_message || '' ) + '" placeholder="Veuillez entrer un ID valide."></label>' +
			'</div>';
		}

		return html;
	}

	function fieldRow( f, allFields ) {
		f = f || {};
		f.id = f.id || uid();
		f.type = TYPES.indexOf( f.type ) > -1 ? f.type : 'number';

		var typeOpts = TYPES.map( function ( t ) {
			return '<option value="' + t + '"' + ( t === f.type ? ' selected' : '' ) + '>' + t + '</option>';
		} ).join( '' );

		var others = allFields.filter( function ( x ) { return x.id !== f.id; } );
		var condsHtml = ( f.conditions || [] ).map( function ( c ) { return conditionRow( c, others ); } ).join( '' );
		var isContent = ( f.type === 'heading' || f.type === 'content' );

		return $( '<div class="dmc-b-row" data-id="' + esc( f.id ) + '">' +
			'<div class="dmc-b-head">' +
				'<span class="dmc-b-handle" title="Drag to reorder">&#9776;</span>' +
				'<select class="dmc-f-type">' + typeOpts + '</select>' +
				'<input type="text" class="dmc-f-label" placeholder="' + ( isContent ? 'Heading / title' : 'Label' ) + '" value="' + esc( f.label ) + '">' +
				'<code class="dmc-f-id">{' + esc( f.id ) + '}</code>' +
				( isContent ? '' : '<label class="dmc-f-req"><input type="checkbox" class="dmc-f-required"' + ( f.required ? ' checked' : '' ) + '> required</label>' ) +
				'<button type="button" class="button-link dmc-f-remove" title="Remove">&times;</button>' +
			'</div>' +
			'<div class="dmc-b-body">' +
				bodyFor( f ) +
				'<div class="dmc-f-conditions"><strong>Show only if</strong> ' +
					'<select class="dmc-f-logic"><option value="all"' + ( f.condition_logic !== 'any' ? ' selected' : '' ) + '>all match</option><option value="any"' + ( f.condition_logic === 'any' ? ' selected' : '' ) + '>any match</option></select>' +
					'<div class="dmc-f-conds">' + condsHtml + '</div>' +
					'<button type="button" class="button dmc-c-add">+ condition</button>' +
				'</div>' +
			'</div>' +
		'</div>' );
	}

	function render() {
		$root.empty();
		state.fields.forEach( function ( f ) { $root.append( fieldRow( f, state.fields ) ); } );
		if ( $root.sortable ) {
			$root.sortable( { handle: '.dmc-b-handle', axis: 'y', update: serialize } );
		}
	}

	function readField( $row ) {
		var t = $row.find( '.dmc-f-type' ).val();
		var f = {
			id: $row.attr( 'data-id' ),
			type: t,
			label: $row.find( '.dmc-f-label' ).val(),
			required: $row.find( '.dmc-f-required' ).is( ':checked' ),
			description: $row.find( '.dmc-f-desc' ).val(),
			condition_logic: $row.find( '.dmc-f-logic' ).val(),
			price: $row.find( '.dmc-f-price' ).val() || 0,
			price_type: $row.find( '.dmc-f-ptype' ).val() || 'fixed',
			appearance: $row.find( '.dmc-f-appearance' ).val() || 'default',
			default: $row.find( '.dmc-f-default' ).val() || '',
			toggle_text: $row.find( '.dmc-f-toggletext' ).val() || '',
			suffix: $row.find( '.dmc-f-suffix' ).val() || '',
			placeholder: $row.find( '.dmc-f-placeholder' ).val() || '',
			icon: $row.find( '.dmc-f-icon' ).val() || '',
			help_enabled: $row.find( '.dmc-f-help-enabled' ).is( ':checked' ),
			help_icon: $row.find( '.dmc-f-help-icon' ).val() || '?',
			help_title: $row.find( '.dmc-f-help-title' ).val() || '',
			help_text: $row.find( '.dmc-f-help-text' ).val() || '',
			help_image: $row.find( '.dmc-f-help-image' ).val() || '',
			help_video: $row.find( '.dmc-f-help-video' ).val() || '',
			min_length: $row.find( '.dmc-f-minlength' ).val() || 0,
			max_length: $row.find( '.dmc-f-maxlength' ).val() || 0,
			pattern: $row.find( '.dmc-f-pattern' ).val() || '',
			transform: $row.find( '.dmc-f-transform' ).val() || 'none',
			error_message: $row.find( '.dmc-f-error' ).val() || '',
			min: '', max: '', step: '', options: [], conditions: []
		};
		if ( isNumeric( t ) ) {
			f.min = $row.find( '.dmc-f-min' ).val();
			f.max = $row.find( '.dmc-f-max' ).val();
			f.step = $row.find( '.dmc-f-step' ).val();
		}
		if ( t === 'content' ) { f.content = $row.find( '.dmc-f-content' ).val() || ''; }
		if ( isChoice( t ) ) {
			$row.find( '.dmc-b-opt' ).each( function () {
				var label = $( this ).find( '.dmc-o-label' ).val();
				var value = $( this ).find( '.dmc-o-value' ).val() || ( label || '' ).toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /^_+|_+$/g, '' );
				f.options.push( {
					label: label,
					value: value,
					price: $( this ).find( '.dmc-o-price' ).val() || 0,
					color: $( this ).find( '.dmc-o-color' ).val() || '',
					image: $( this ).find( '.dmc-o-image' ).val() || '',
					desc: $( this ).find( '.dmc-o-desc' ).val() || ''
				} );
			} );
		}
		$row.find( '.dmc-b-cond' ).each( function () {
			var fld = $( this ).find( '.dmc-c-field' ).val();
			if ( ! fld ) { return; }
			f.conditions.push( { field: fld, op: $( this ).find( '.dmc-c-op' ).val(), value: $( this ).find( '.dmc-c-value' ).val() } );
		} );
		return f;
	}

	function readDesign(){ var d={}; $('.dmc-design').each(function(){ var $x=$(this), k=$x.data('key'); d[k]=$x.is(':checkbox')?$x.is(':checked'):$x.val(); }); return $.extend({},designDefaults,d); }

	function hydrateDesign(){ $('.dmc-design').each(function(){ var $x=$(this), v=state.design[$x.data('key')]; if($x.is(':checkbox')){$x.prop('checked',!!v);}else{$x.val(v);} }); }

	function serialize() {
		state.fields = [];
		state.design = readDesign();
		$root.find( '.dmc-b-row' ).each( function () { state.fields.push( readField( $( this ) ) ); } );
		$store.val( JSON.stringify( {
			enabled: $( 'input[name="dmc_calc_enabled"]' ).is( ':checked' ),
			formula: $( '#dmc-formula' ).val(),
			design: state.design,
			fields: state.fields
		} ) );
	}

	var templates = {
		freefire: [
			{ type:'text', label:'Player ID', required:true, placeholder:'Ex: 548392311', icon:'🎮', min_length:6, max_length:15, transform:'digits', pattern:'[0-9]{6,15}', error_message:'Veuillez entrer un Player ID valide.' },
			{ type:'select', label:'Région du compte', required:true, icon:'🌍', options:[{label:'Amérique',value:'america',price:0},{label:'Brésil',value:'brazil',price:0},{label:'Europe',value:'europe',price:0}] }
		],
		gaming: [
			{ type:'text', label:'Player ID / UID', required:true, placeholder:'Entrez votre identifiant', icon:'👤', min_length:4, max_length:30, error_message:'Cet identifiant est obligatoire.' },
			{ type:'text', label:'Pseudo', placeholder:'Nom affiché dans le jeu', icon:'✨', max_length:40 }
		],
		giftcard: [
			{ type:'email', label:'E-mail de livraison', required:true, placeholder:'client@email.com', icon:'✉️', error_message:'Veuillez entrer une adresse e-mail valide.' },
			{ type:'select', label:'Région de la carte', required:true, icon:'🌍', options:[{label:'USA',value:'usa',price:0},{label:'Canada',value:'canada',price:0},{label:'France',value:'france',price:0}] }
		],
		subscription: [
			{ type:'email', label:'Adresse e-mail', required:true, placeholder:'client@email.com', icon:'✉️', error_message:'Veuillez entrer une adresse e-mail valide.' },
			{ type:'text', label:'Nom du profil (optionnel)', required:false, placeholder:'Ex: Marken', icon:'👤', max_length:60 }
		],
		netflix: [
			{ type:'email', label:'Adresse e-mail', required:true, placeholder:'client@email.com', icon:'✉️', error_message:'Veuillez entrer une adresse e-mail valide.' },
			{ type:'text', label:'Nom du profil (optionnel)', required:false, placeholder:'Ex: Profil 1', icon:'👤', max_length:60 }
		],
		dls: [
			{ type:'email', label:'Adresse e-mail', required:true, placeholder:'client@email.com', icon:'✉️', error_message:'Veuillez entrer une adresse e-mail valide.' },
			{ type:'text', label:'ID de compte KONAMI', required:true, placeholder:'Entrez l’identifiant du compte', icon:'', min_length:4, max_length:120, error_message:'Veuillez entrer l’identifiant du compte.' },
			{ type:'tel', label:'Numéro WhatsApp', required:true, placeholder:'Ex: +509 37 00 00 00', icon:'📱', min_length:7, max_length:24, error_message:'Veuillez entrer un numéro WhatsApp valide.' }
		],
		codm: [
			{ type:'text', label:'Player ID / UID', required:true, placeholder:'Entrez votre Player ID', icon:'🎮', min_length:4, max_length:40, error_message:'Veuillez entrer votre Player ID.' },
			{ type:'select', label:'Région', required:true, icon:'🌍', options:[{label:'LATAM',value:'latam',price:0},{label:'USA',value:'usa',price:0},{label:'Europe',value:'europe',price:0}] },
			{ type:'tel', label:'Numéro WhatsApp (optionnel)', required:false, placeholder:'Ex: +509 37 00 00 00', icon:'📱', min_length:7, max_length:24 }
		],
		wallet: [
			{ type:'tel', label:'Numéro du compte', required:true, placeholder:'Ex: 509XXXXXXXX', icon:'📱', min_length:8, max_length:20, transform:'digits', error_message:'Veuillez entrer un numéro valide.' },
			{ type:'text', label:'Nom du titulaire', required:true, icon:'👤', min_length:2, max_length:80 }
		]
	};
	$(document).on('click','.dmc-template',function(){
		serialize(); var key=$(this).data('template'), list=templates[key]||[];
		list.forEach(function(f){ f=$.extend(true,{},f); f.id=uid(); f.price=0; f.price_type='fixed'; state.fields.push(f); });
		render(); serialize();
	});

	$( '#dmc-builder-add' ).on( 'click', function () {
		serialize();
		state.fields.push( { id: uid(), type: 'number', label: '', price: 0, price_type: 'fixed' } );
		render();
	} );

	$root.on( 'click', '.dmc-f-remove', function () { $( this ).closest( '.dmc-b-row' ).remove(); serialize(); } );
	$root.on( 'change', '.dmc-f-type, .dmc-f-appearance', function () { serialize(); render(); } );

	$root.on( 'click', '.dmc-o-add', function () {
		var app = $( this ).closest( '.dmc-b-row' ).find( '.dmc-f-appearance' ).val() || 'default';
		$( this ).siblings( '.dmc-f-opts' ).append( optionRow( null, app ) );
		serialize();
	} );
	$root.on( 'click', '.dmc-o-remove', function () { $( this ).closest( '.dmc-b-opt' ).remove(); serialize(); } );

	$root.on( 'click', '.dmc-c-add', function () {
		var $row = $( this ).closest( '.dmc-b-row' );
		serialize();
		var others = state.fields.filter( function ( x ) { return x.id !== $row.attr( 'data-id' ); } );
		$( this ).siblings( '.dmc-f-conds' ).append( conditionRow( null, others ) );
	} );
	$root.on( 'click', '.dmc-c-remove', function () { $( this ).closest( '.dmc-b-cond' ).remove(); serialize(); } );

	$root.on( 'input change', 'input, select, textarea', serialize );
	$( '#dmc-formula, input[name="dmc_calc_enabled"]' ).on( 'input change', serialize );
	$( '#post' ).on( 'submit', serialize );
	$(document).on('input change','.dmc-design',serialize);
	$root.on('click','.dmc-media-pick',function(){ var $input=$(this).siblings('input'); if(!window.wp||!wp.media){return;} var frame=wp.media({title:'Choisir une image ou vidéo',button:{text:'Utiliser ce média'},multiple:false}); frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();$input.val(a.url).trigger('input');}); frame.open(); });

	hydrateDesign();
	render();
	serialize();
} )( jQuery );
