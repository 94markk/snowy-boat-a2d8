(() => {
	'use strict';

	const config = window.DelicaBuilderEditor || {};
	const canvas = document.getElementById('delicat-builder-canvas');
	const library = document.getElementById('delicat-section-library');
	const inspector = document.getElementById('delicat-builder-inspector');
	const field = document.getElementById('delicat-builder-layout-json');
	const count = document.getElementById('delicat-section-count');
	if (!canvas || !library || !inspector || !field) return;

	const clone = (value) => JSON.parse(JSON.stringify(value));
	let layout = Array.isArray(config.layout) ? clone(config.layout) : [];
	let selectedId = layout[0]?.id || null;
	let dragId = null;

	const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
	const sync = () => {
		field.value = JSON.stringify(layout);
		if (count) count.textContent = ` ${layout.length}/${config.max || 40}`;
	};

	const titleFor = (section) => {
		const c = section.content || {};
		return c.title || config.types?.[section.type]?.label || section.type;
	};

	const renderLibrary = () => {
		library.innerHTML = '';
		Object.entries(config.types || {}).forEach(([type, meta]) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'delicat-library-item';
			button.innerHTML = `<strong>${esc(meta.label)}</strong><span>${esc(meta.description)}</span>`;
			button.addEventListener('click', () => addSection(type));
			library.appendChild(button);
		});
	};

	const addSection = (type) => {
		if (layout.length >= (config.max || 40)) {
			window.alert(`Maximum ${config.max || 40} sections in ${config.version || 'Delicat Builder'}.`);
			return;
		}
		const keys = Object.keys(config.types || {});
		const idx = keys.indexOf(type);
		const defaults = Array.isArray(config.defaults) ? config.defaults[idx] : null;
		if (!defaults) return;
		const section = clone(defaults);
		section.id = crypto?.randomUUID ? crypto.randomUUID() : `dbv9-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		layout.push(section);
		selectedId = section.id;
		render();
	};

	const removeSection = (id) => {
		if (!window.confirm('Remove this section?')) return;
		layout = layout.filter((item) => item.id !== id);
		selectedId = layout[0]?.id || null;
		render();
	};

	const duplicateSection = (id) => {
		if (layout.length >= (config.max || 40)) return;
		const index = layout.findIndex((item) => item.id === id);
		if (index < 0) return;
		const copy = clone(layout[index]);
		copy.id = crypto?.randomUUID ? crypto.randomUUID() : `dbv9-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		layout.splice(index + 1, 0, copy);
		selectedId = copy.id;
		render();
	};

	const move = (fromId, toId) => {
		if (!fromId || !toId || fromId === toId) return;
		const from = layout.findIndex((item) => item.id === fromId);
		const to = layout.findIndex((item) => item.id === toId);
		if (from < 0 || to < 0) return;
		const [item] = layout.splice(from, 1);
		layout.splice(to, 0, item);
		renderCanvas();
		sync();
	};

	const renderCanvas = () => {
		canvas.innerHTML = '';
		if (!layout.length) {
			canvas.innerHTML = '<div class="delicat-canvas-empty"><strong>Your page is empty.</strong><span>Add a section from the left panel. No extra frontend JS is created for static sections.</span></div>';
			return;
		}

		layout.forEach((section, index) => {
			const card = document.createElement('article');
			card.className = `delicat-canvas-card${selectedId === section.id ? ' is-selected' : ''}`;
			card.draggable = true;
			card.dataset.id = section.id;
			card.innerHTML = `
				<div class="delicat-canvas-card__drag" title="Drag to reorder">⋮⋮</div>
				<div class="delicat-canvas-card__body">
					<span class="delicat-canvas-card__type">${esc(config.types?.[section.type]?.label || section.type)}</span>
					<strong>${esc(titleFor(section))}</strong>
					<small>Section ${index + 1}</small>
				</div>
				<div class="delicat-canvas-card__actions">
					<button type="button" data-action="duplicate" title="Duplicate">⧉</button>
					<button type="button" data-action="remove" title="Remove">×</button>
				</div>`;
			card.addEventListener('click', (event) => {
				const action = event.target.closest('[data-action]')?.dataset.action;
				if (action === 'remove') return removeSection(section.id);
				if (action === 'duplicate') return duplicateSection(section.id);
				selectedId = section.id;
				renderCanvas();
				renderInspector();
			});
			card.addEventListener('dragstart', () => {
				dragId = section.id;
				card.classList.add('is-dragging');
			});
			card.addEventListener('dragend', () => {
				dragId = null;
				card.classList.remove('is-dragging');
			});
			card.addEventListener('dragover', (event) => event.preventDefault());
			card.addEventListener('drop', (event) => {
				event.preventDefault();
				move(dragId, section.id);
			});
			canvas.appendChild(card);
		});
	};

	const sectionInput = (label, path, value, type = 'text', attrs = '') => `
		<label class="delicat-inspector-field">
			<span>${esc(label)}</span>
			<input type="${type}" data-path="${esc(path)}" value="${esc(value)}" ${attrs}>
		</label>`;

	const sectionTextarea = (label, path, value) => `
		<label class="delicat-inspector-field">
			<span>${esc(label)}</span>
			<textarea data-path="${esc(path)}" rows="5">${esc(value)}</textarea>
		</label>`;

	const selectField = (label, path, value, options) => `
		<label class="delicat-inspector-field"><span>${esc(label)}</span><select data-path="${esc(path)}">${options.map(([v,l]) => `<option value="${esc(v)}"${String(value)===String(v)?' selected':''}>${esc(l)}</option>`).join('')}</select></label>`;

	const checkbox = (label, path, checked) => `
		<label class="delicat-inspector-check"><input type="checkbox" data-path="${esc(path)}" ${checked ? 'checked' : ''}><span>${esc(label)}</span></label>`;

	const renderInspector = () => {
		const section = layout.find((item) => item.id === selectedId);
		if (!section) {
			inspector.innerHTML = '<p class="description">Select a section to edit it.</p>';
			return;
		}

		const c = section.content || {};
		let html = `<div class="delicat-inspector-heading"><strong>${esc(config.types?.[section.type]?.label || section.type)}</strong><small>${esc(section.id)}</small></div>`;

		if (section.type === 'hero') {
			html += selectField('Hero style', 'content.style', c.style || 'neo_glass_pro', [['neo_glass_pro','NeoGlass Pro — fastest premium'],['minimal','Minimal'],['solid','Solid']]);
			html += sectionInput('Eyebrow', 'content.eyebrow', c.eyebrow);
			html += sectionInput('Title', 'content.title', c.title);
			html += sectionInput('Highlighted phrase — must match part of title', 'content.highlight_text', c.highlight_text || '');
			html += sectionTextarea('Text', 'content.text', c.text);
			html += sectionInput('Button text', 'content.button_text', c.button_text);
			html += sectionInput('Button URL', 'content.button_url', c.button_url, 'url');
			html += sectionTextarea('Trust chips — icon token|label, max 3', 'content.benefits', c.benefits || 'lightning|Livraison instantanée\nshield|Paiement sécurisé\nheadset|Support 24/7');
			html += '<p class="description">Built-in fast icons: lightning, shield, headset, verified, globe, tag. No external icon library is loaded.</p>';
			html += imageField(c.image_id || 0, 'Desktop / main image (optional)', 'content.image_id');
			html += imageField(c.mobile_image_id || 0, 'Mobile image (optional)', 'content.mobile_image_id');
			html += selectField('Media position', 'content.media_position', c.media_position || 'right', [['right','Right'],['left','Left']]);
			html += '<hr><h3>Hero background image</h3>';
			html += imageField(c.background_image_id || 0, 'Desktop background image', 'content.background_image_id');
			html += imageField(c.mobile_background_image_id || 0, 'Mobile background image (optional)', 'content.mobile_background_image_id');
			html += sectionInput('Background overlay darkness %', 'content.background_overlay', c.background_overlay ?? 46, 'number', 'min="0" max="85"');
			html += selectField('Background focal position', 'content.background_position', c.background_position || 'center', [['center','Center'],['top','Top'],['bottom','Bottom'],['left','Left'],['right','Right']]);
			html += '<p class="description">Use WordPress Media Library images only. A separate mobile image keeps the hero sharp without downloading an oversized desktop file.</p>';
			html += '<hr><h3>Live product search</h3>';
			html += checkbox('Show search bar in hero', 'content.search_enabled', c.search_enabled !== false && c.search_enabled !== 0);
			html += sectionInput('Search placeholder', 'content.search_placeholder', c.search_placeholder || 'Rechercher un jeu, une carte ou un service…');
			html += sectionInput('Products indexed — lower = lighter page', 'content.search_index_limit', c.search_index_limit ?? 60, 'number', 'min="10" max="120"');
			html += sectionInput('Suggestions shown', 'content.search_results_limit', c.search_results_limit ?? 5, 'number', 'min="3" max="8"');
			html += sectionInput('Start suggestions after characters', 'content.search_min_chars', c.search_min_chars ?? 2, 'number', 'min="1" max="4"');
			html += '<p class="description">Suggestions are searched locally from a cached public WooCommerce index: no API key and no request on every keystroke.</p>';
			html += sectionInput('Minimum hero height', 'content.min_height', c.min_height || 320, 'number', 'min="220" max="760"');
			html += selectField('Alignment', 'content.align', c.align, [['left','Left'],['center','Center']]);
			html += '<hr><h3>Modern background — floating icons</h3>';
			html += checkbox('Floating icons behind the copy (bounce smoothly, CSS only)', 'content.float_enabled', c.float_enabled !== false && c.float_enabled !== 0);
			html += sectionTextarea('Icons — token|colour, one per line (up to 10)', 'content.float_icons', c.float_icons ?? 'gamepad|#6a5cff\ngift|#e0407f\ntv|#22a06b\ncoins|#d9860c\nsparkles|#8b5cf6\nbolt|#1f7fd6\nheart|#ff4d91\ncrown|#f0b429');
			html += '<p class="description">Icon: a token (gamepad, gift, tv, coins, sparkles, bolt, heart, crown, star, trophy, ticket, percent, diamond, store, card, shield, globe, headset, lightning, verified, tag, phone, wallet, mail, lock, search) or any emoji. Colour is optional. Each line has its own slot, speed and delay: desktop shows up to 10, tablets 7, phones 4 (lines 1, 2, 3 and 5 — the slots that clear the text), low-power devices at most 4.</p>';
			html += sectionInput('Tile size px — desktop', 'content.float_size', c.float_size ?? 52, 'number', 'min="28" max="96"');
			html += sectionInput('Tile size px — phones', 'content.float_size_m', c.float_size_m ?? 58, 'number', 'min="28" max="96"');
			html += sectionInput('Tile opacity %', 'content.float_opacity', c.float_opacity ?? 62, 'number', 'min="10" max="100"');
			html += sectionInput('Bounce speed — seconds per cycle (lower = faster)', 'content.float_speed', c.float_speed ?? 7, 'number', 'min="3" max="16"');
			html += checkbox('Dot grid pattern on the hero background', 'content.pattern_enabled', c.pattern_enabled !== false && c.pattern_enabled !== 0);
			html += '<hr><h3>Gradient background</h3>';
			html += selectField('Background', 'content.background_style', c.background_style || 'mesh', [['mesh','Mesh gradient — colour stops + four glows (RC64)'],['glass','Pale glass (previous look)']]);
			html += sectionInput('Gradient start', 'content.bg_start', c.bg_start || '#eceeff', 'text', 'maxlength="7"');
			html += sectionInput('Gradient middle', 'content.bg_mid', c.bg_mid || '#f6ecff', 'text', 'maxlength="7"');
			html += sectionInput('Gradient end', 'content.bg_end', c.bg_end || '#ffe9f0', 'text', 'maxlength="7"');
			html += sectionInput('Glow 1 — top left', 'content.glow_1', c.glow_1 || '#7d6cff', 'text', 'maxlength="7"');
			html += sectionInput('Glow 2 — top right', 'content.glow_2', c.glow_2 || '#ff4d91', 'text', 'maxlength="7"');
			html += sectionInput('Glow 3 — bottom right', 'content.glow_3', c.glow_3 || '#28c8ff', 'text', 'maxlength="7"');
			html += sectionInput('Glow 4 — bottom left', 'content.glow_4', c.glow_4 || '#ffb547', 'text', 'maxlength="7"');
			html += sectionInput('Glow strength %', 'content.glow_strength', c.glow_strength ?? 36, 'number', 'min="0" max="100"');
		} else if (section.type === 'text') {
			html += sectionInput('Title', 'content.title', c.title);
			html += sectionTextarea('Text', 'content.text', c.text);
			html += selectField('Alignment', 'content.align', c.align, [['left','Left'],['center','Center']]);
		} else if (section.type === 'banner') {
			html += sectionInput('Title', 'content.title', c.title);
			html += sectionTextarea('Text', 'content.text', c.text);
			html += sectionInput('Button text', 'content.button_text', c.button_text);
			html += sectionInput('Button URL', 'content.button_url', c.button_url, 'url');
			html += imageField(c.image_id || 0, 'Banner image', 'content.image_id');
		} else if (section.type === 'products') {
			html += sectionInput('Eyebrow / small label', 'content.eyebrow', c.eyebrow || '');
			html += sectionInput('Title', 'content.title', c.title);
			html += selectField('Display style', 'content.display_variant', c.display_variant || 'standard', [['standard','Standard carousel'],['ranking','Ranking / Les plus achetés']]);
			html += selectField('Heading level', 'content.heading_level', c.heading_level || 'h2', [['h1','H1'],['h2','H2'],['h3','H3']]);
			html += sectionInput('Title size desktop — 0 = global', 'content.title_size_d', c.title_size_d || 0, 'number', 'min="0" max="64"');
			html += sectionInput('Title size tablet — 0 = global', 'content.title_size_t', c.title_size_t || 0, 'number', 'min="0" max="56"');
			html += sectionInput('Title size mobile — 0 = global', 'content.title_size_m', c.title_size_m || 0, 'number', 'min="0" max="48"');
			html += sectionInput('Title color — blank = global', 'content.title_color', c.title_color || '', 'text', 'placeholder="#111827" maxlength="7"');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += sectionInput('Subtitle color — blank = global', 'content.subtitle_color', c.subtitle_color || '', 'text', 'placeholder="#667085" maxlength="7"');

			html += '<hr><h3>Carousel spacing</h3>';
			html += selectField('Card gap source', 'content.gap_mode', c.gap_mode || 'global', [['global','Use Design Studio global gap'],['custom','Custom gap for this carousel']]);
			html += sectionInput('Card gap desktop', 'content.card_gap_d', c.card_gap_d ?? 0, 'number', 'min="0" max="48"');
			html += sectionInput('Card gap tablet', 'content.card_gap_t', c.card_gap_t ?? 0, 'number', 'min="0" max="40"');
			html += sectionInput('Card gap mobile', 'content.card_gap_m', c.card_gap_m ?? 0, 'number', 'min="0" max="32"');
			html += sectionInput('Gap after carousel desktop', 'content.section_gap_d', c.section_gap_d ?? 38, 'number', 'min="0" max="120"');
			html += sectionInput('Gap after carousel tablet', 'content.section_gap_t', c.section_gap_t ?? 34, 'number', 'min="0" max="100"');
			html += sectionInput('Gap after carousel mobile', 'content.section_gap_m', c.section_gap_m ?? 30, 'number', 'min="0" max="90"');

			html += '<hr><h3>Product typography</h3>';
			html += sectionInput('Product name size desktop — 0 = global', 'content.product_name_d', c.product_name_d || 0, 'number', 'min="0" max="36"');
			html += sectionInput('Product name size tablet — 0 = global', 'content.product_name_t', c.product_name_t || 0, 'number', 'min="0" max="32"');
			html += sectionInput('Product name size mobile — 0 = global', 'content.product_name_m', c.product_name_m || 0, 'number', 'min="0" max="28"');
			html += sectionInput('Image title size desktop', 'content.media_title_d', c.media_title_d ?? 30, 'number', 'min="14" max="52"');
			html += sectionInput('Image title size tablet', 'content.media_title_t', c.media_title_t ?? 25, 'number', 'min="14" max="44"');
			html += sectionInput('Image title size mobile', 'content.media_title_m', c.media_title_m ?? 20, 'number', 'min="12" max="36"');

			html += '<hr><h3>Badge engine</h3>';
			html += selectField('Badge source', 'content.badge_mode', c.badge_mode || 'auto', [['auto','Auto'],['category','Woo category'],['tag','Woo product tag'],['sale','Sale / Promo'],['stock','Stock status'],['custom','Custom text'],['off','Off']]);
			html += sectionInput('Custom badge / auto override', 'content.tag_text', c.tag_text || '', 'text', 'maxlength="40"');
			html += sectionInput('Badge background — blank = global', 'content.badge_bg', c.badge_bg || '', 'text', 'placeholder="#e9ddff" maxlength="7"');
			html += sectionInput('Badge text color — blank = global', 'content.badge_text', c.badge_text || '', 'text', 'placeholder="#6540d9" maxlength="7"');
			html += selectField('Status badge', 'content.status_badge_mode', c.status_badge_mode || 'auto', [['auto','Auto: Promo → Top Vente → Nouveau'],['sale','Promo only'],['bestseller','Top Vente only'],['new','Nouveau only'],['off','Off']]);
			html += sectionInput('New badge window — days', 'content.status_new_days', c.status_new_days ?? 30, 'number', 'min="1" max="120"');
			html += sectionInput('Top Vente minimum total sales', 'content.status_sales_min', c.status_sales_min ?? 10, 'number', 'min="1" max="10000"');
			html += selectField('Pagination dots', 'content.pagination_mode', c.pagination_mode || 'pages', [['pages','Pages'],['cards','Snap positions — Lovable style']]);

			html += '<hr><h3>Heart engine</h3>';
			html += selectField('Heart mode', 'content.heart_mode', c.heart_mode || 'auto', [['auto','Inherit global'],['on','On (global engine required)'],['off','Off']]);
			html += sectionInput('Heart background — blank = global', 'content.heart_bg', c.heart_bg || '', 'text', 'placeholder="#ffffff" maxlength="7"');
			html += sectionInput('Heart icon color — blank = global', 'content.heart_color', c.heart_color || '', 'text', 'placeholder="#ff4d91" maxlength="7"');

			html += sectionInput('View all text', 'content.view_all_text', c.view_all_text || 'Voir tout');
			html += sectionInput('View all URL', 'content.view_all_url', c.view_all_url || '', 'url');
			html += sectionInput('Category slug (fallback)', 'content.category', c.category);
			html += sectionInput('Exact product IDs — comma separated, up to 24', 'content.product_ids', c.product_ids || '');
			html += '<p class="description">When Exact Product IDs are filled, all valid IDs are used in that order. The category product limit below does not cut the exact list.</p>';
			html += sectionInput('Maximum products — category mode only', 'content.limit', c.limit, 'number', 'min="1" max="24"');
			html += selectField('Order by', 'content.orderby', c.orderby, [['date','Date'],['modified','Modified'],['title','Title'],['menu_order','Menu order'],['popularity','Best sellers / total sales'],['rand','Random']]);
			html += selectField('Order', 'content.order', c.order, [['DESC','Descending'],['ASC','Ascending']]);
			html += selectField('Card style', 'content.style', c.style || 'delicat_jeux', [['delicat_jeux','Delicat Jeux'],['delicat_abonnement','Delicat Abonnement Premium']]);
			html += checkbox('Show price', 'content.show_price', c.show_price !== false);
			html += checkbox('Show stock badge', 'content.show_stock', c.show_stock !== false);
			html += checkbox('Show Acheter button', 'content.show_cta', c.show_cta !== false);
			html += checkbox('Progressive card loading', 'content.progressive', c.progressive !== false);
		} else if (section.type === 'how_it_works') {
			html += selectField('Look', 'content.style', c.style || 'ultra', [['ultra','Ultra — reference (light cards, numbered 01/02/03)'],['neon','Neon — previous dark cards']]);
			html += sectionInput('Eyebrow (small label above the title)', 'content.eyebrow', c.eyebrow ?? 'Simple comme bonjour');
			html += sectionInput('Eyebrow color', 'content.eyebrow_color', c.eyebrow_color || '#6a5cff', 'text', 'maxlength="7"');
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 38, 'number', 'min="18" max="64"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 36, 'number', 'min="18" max="56"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 28, 'number', 'min="18" max="48"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '', 'text', 'placeholder="#f8f8ff" maxlength="7"');
			html += sectionInput('Subtitle color', 'content.subtitle_color', c.subtitle_color || '', 'text', 'placeholder="#989bad" maxlength="7"');
			html += sectionTextarea('Steps — icon|title|text, one per line', 'content.steps', c.steps || '');
			html += '<p class="description">Icon tokens: store, card, bolt, cursor, chat, rocket, lightning, shield, globe, headset, verified, tag, phone, wallet, mail, lock, search, heart, sparkles, gift, gamepad, tv, coins — or any emoji.</p>';
			html += '<hr><h3>Ultra cards</h3>';
			html += sectionInput('Card background', 'content.ultra_bg', c.ultra_bg || '#f4f3ff', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.ultra_border', c.ultra_border || '#e6e4fb', 'text', 'maxlength="7"');
			html += sectionInput('Card title color', 'content.ultra_title', c.ultra_title || '#111827', 'text', 'maxlength="7"');
			html += sectionInput('Card text color', 'content.ultra_text', c.ultra_text || '#6b7280', 'text', 'maxlength="7"');
			html += sectionInput('Icon tile background', 'content.ultra_icon_bg', c.ultra_icon_bg || '#ffffff', 'text', 'maxlength="7"');
			html += sectionInput('Icon color', 'content.ultra_icon', c.ultra_icon || '#6a5cff', 'text', 'maxlength="7"');
			html += sectionInput('Step number color', 'content.ultra_number', c.ultra_number || '#dcdfe8', 'text', 'maxlength="7"');
			html += sectionInput('Card radius', 'content.ultra_radius', c.ultra_radius ?? 14, 'number', 'min="8" max="48"');
			html += '<hr><h3>Neon cards (previous look)</h3>';
			html += sectionInput('Card background', 'content.card_bg', c.card_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.card_border', c.card_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Card title color', 'content.card_title', c.card_title || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Card text color', 'content.card_text', c.card_text || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Accent 1', 'content.accent_1', c.accent_1 || '#5964ff', 'text', 'maxlength="7"');
			html += sectionInput('Accent 2', 'content.accent_2', c.accent_2 || '#ee5abd', 'text', 'maxlength="7"');
			html += sectionInput('Accent 3', 'content.accent_3', c.accent_3 || '#56d8f1', 'text', 'maxlength="7"');
			html += sectionInput('Card gap', 'content.card_gap', c.card_gap ?? 18, 'number', 'min="0" max="48"');
			html += sectionInput('Card radius', 'content.card_radius', c.card_radius ?? 30, 'number', 'min="8" max="48"');
			html += sectionInput('Large number opacity %', 'content.number_opacity', c.number_opacity ?? 7, 'number', 'min="0" max="30"');

		} else if (section.type === 'testimonials') {
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 38, 'number', 'min="18" max="64"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 36, 'number', 'min="18" max="56"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 28, 'number', 'min="18" max="48"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle color', 'content.subtitle_color', c.subtitle_color || '', 'text', 'maxlength="7"');
			html += sectionTextarea('Testimonials — quote|name|location|rating|product id or URL (optional), one per line', 'content.items', c.items || '');
			html += '<p class="description">Approved WooCommerce product reviews (Products → Reviews) and popup reviews are merged in front of these automatically; each links to its product.</p>';
			html += checkbox('Link cards to the reviewed WooCommerce product', 'content.link_products', c.link_products !== false && c.link_products !== 0);
			html += '<hr><h3>Cards</h3>';
			html += sectionInput('Card background', 'content.card_bg', c.card_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.card_border', c.card_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Quote color', 'content.quote_color', c.quote_color || '#5964ff', 'text', 'maxlength="7"');
			html += sectionInput('Review text color', 'content.text_color', c.text_color || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Customer name color', 'content.name_color', c.name_color || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Stars color', 'content.stars_color', c.stars_color || '#ffbf2f', 'text', 'maxlength="7"');
			html += sectionInput('Card gap', 'content.card_gap', c.card_gap ?? 24, 'number', 'min="0" max="48"');
			html += sectionInput('Card width desktop px', 'content.card_width_d', c.card_width_d ?? 560, 'number', 'min="280" max="760"');
			html += sectionInput('Card width mobile vw', 'content.card_width_m', c.card_width_m ?? 76, 'number', 'min="68" max="92"');
			html += '<hr><h3>Verified rating</h3>';
			html += sectionInput('Rating value', 'content.rating_value', c.rating_value || '4.8', 'text', 'maxlength="8"');
			html += sectionInput('Verified review count', 'content.rating_count', c.rating_count ?? 5, 'number', 'min="0" max="100000"');
			html += sectionInput('Rating label', 'content.rating_label', c.rating_label || 'AVIS VÉRIFIÉS');
			html += '<hr><h3>Review CTA</h3>';
			html += sectionInput('CTA title', 'content.review_title', c.review_title || '');
			html += sectionInput('CTA text', 'content.review_text', c.review_text || '');
			html += sectionInput('CTA button', 'content.review_button', c.review_button || '');
			html += sectionInput('CTA URL', 'content.review_url', c.review_url || '', 'url');
			html += '<hr><h3>Google</h3>';
			html += sectionInput('Google review link (Business Profile « Écrire un avis » URL) — blank hides the button', 'content.google_review_url', c.google_review_url || '', 'url');
			html += sectionInput('Google button text', 'content.google_button', c.google_button ?? 'Noter sur Google');
			html += '<p class="description">The stars Google shows next to the store name come from its Business Profile reviews; product stars in search results come from WooCommerce product reviews (Product structured data, RC63).</p>';

		} else if (section.type === 'faq') {
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 38, 'number', 'min="18" max="64"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 36, 'number', 'min="18" max="56"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 28, 'number', 'min="18" max="48"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle color', 'content.subtitle_color', c.subtitle_color || '', 'text', 'maxlength="7"');
			html += sectionTextarea('FAQ items — question|answer, one per line', 'content.items', c.items || '');
			html += sectionInput('Open item index (starts at 0)', 'content.open_index', c.open_index ?? 0, 'number', 'min="0" max="20"');
			html += '<hr><h3>FAQ cards</h3>';
			html += sectionInput('Card background', 'content.card_bg', c.card_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.card_border', c.card_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Question color', 'content.question_color', c.question_color || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Answer color', 'content.answer_color', c.answer_color || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Plus/minus color', 'content.accent_color', c.accent_color || '#5964ff', 'text', 'maxlength="7"');
			html += sectionInput('Card gap', 'content.card_gap', c.card_gap ?? 14, 'number', 'min="0" max="40"');
			html += sectionInput('Card radius', 'content.card_radius', c.card_radius ?? 28, 'number', 'min="8" max="48"');

		} else if (section.type === 'why_delicat') {
			html += selectField('Look', 'content.style', c.style || 'ultra', [['ultra','Ultra — reference (white cards 2×2 + Haïti · Diaspora call-to-action)'],['neon','Neon — previous dark cards + payment methods']]);
			html += sectionInput('Eyebrow', 'content.eyebrow', c.eyebrow || '');
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle (ultra)', 'content.subtitle', c.subtitle ?? 'Une plateforme rapide, des informations claires et des commandes contrôlées.');
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 38, 'number', 'min="18" max="64"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 36, 'number', 'min="18" max="56"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 28, 'number', 'min="18" max="48"');
			html += sectionInput('Eyebrow color', 'content.eyebrow_color', c.eyebrow_color || '#6a64ff', 'text', 'maxlength="7"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionTextarea('Benefits — icon token|title|text, one per line', 'content.items', c.items || '');
			html += '<p class="description">Icon tokens: bolt, shield, globe, headset, lightning, verified, tag, store, card, heart, sparkles, gift, gamepad, tv, coins — or any emoji.</p>';
			html += '<hr><h3>Ultra cards</h3>';
			html += sectionInput('Card background', 'content.ultra_bg', c.ultra_bg || '#ffffff', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.ultra_border', c.ultra_border || '#e8e6f7', 'text', 'maxlength="7"');
			html += sectionInput('Title / card title color', 'content.ultra_title', c.ultra_title || '#111827', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle / card text color', 'content.ultra_text', c.ultra_text || '#6b7280', 'text', 'maxlength="7"');
			html += sectionInput('Card radius', 'content.ultra_radius', c.ultra_radius ?? 14, 'number', 'min="8" max="48"');
			html += '<hr><h3>Call-to-action panel (ultra)</h3>';
			html += sectionInput('Panel eyebrow', 'content.cta_eyebrow', c.cta_eyebrow ?? 'Haïti · Diaspora');
			html += sectionInput('Panel icon token', 'content.cta_icon', c.cta_icon || 'globe');
			html += sectionInput('Panel title — blank hides the panel', 'content.cta_title', c.cta_title ?? 'Fais plaisir à un proche, simplement.');
			html += sectionTextarea('Panel text', 'content.cta_text', c.cta_text ?? 'Jeux, abonnements et cartes cadeaux : choisis le service, règle en toute sécurité, puis suis la livraison dans ton espace client.');
			html += sectionInput('Button text', 'content.cta_button', c.cta_button ?? 'Découvrir les services');
			html += sectionInput('Button URL', 'content.cta_url', c.cta_url ?? '/shop/', 'url');
			html += sectionInput('Panel gradient start', 'content.cta_bg_start', c.cta_bg_start || '#ecebff', 'text', 'maxlength="7"');
			html += sectionInput('Panel gradient end', 'content.cta_bg_end', c.cta_bg_end || '#fde7f0', 'text', 'maxlength="7"');
			html += sectionInput('Button gradient start', 'content.cta_button_start', c.cta_button_start || '#5b5bd6', 'text', 'maxlength="7"');
			html += sectionInput('Button gradient end', 'content.cta_button_end', c.cta_button_end || '#e0407f', 'text', 'maxlength="7"');
			html += '<hr><h3>Neon cards (previous look)</h3>';
			html += sectionInput('Card background', 'content.card_bg', c.card_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.card_border', c.card_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Card title color', 'content.card_title', c.card_title || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Card text color', 'content.card_text', c.card_text || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Card radius', 'content.card_radius', c.card_radius ?? 28, 'number', 'min="8" max="48"');
			html += sectionInput('Card gap', 'content.card_gap', c.card_gap ?? 14, 'number', 'min="0" max="40"');
			html += '<hr><h3>Payment methods</h3>';
			html += sectionInput('Payment heading', 'content.payment_title', c.payment_title || '');
			html += sectionTextarea('Payments — icon token|label, one per line', 'content.payments', c.payments || '');
			html += '<p class="description">Built-in payment icon tokens: phone, wallet, card.</p>';
			html += sectionTextarea('Payment note', 'content.payment_note', c.payment_note || '');

		} else if (section.type === 'favorites') {
			html += sectionInput('Eyebrow', 'content.eyebrow', c.eyebrow || '');
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += selectField('Heading alignment', 'content.align', c.align || 'left', [['left','Left'],['center','Center']]);
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 38, 'number', 'min="18" max="64"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 36, 'number', 'min="18" max="56"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 28, 'number', 'min="18" max="48"');
			html += sectionInput('Eyebrow color', 'content.eyebrow_color', c.eyebrow_color || '#6a5cff', 'text', 'maxlength="7"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '#111827', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle color', 'content.subtitle_color', c.subtitle_color || '#6b7280', 'text', 'maxlength="7"');
			html += sectionTextarea('Cards — icon|title|text|URL|colour, one per line (up to 12)', 'content.items', c.items || '');
			html += '<p class="description">Icon: a token (gamepad, tv, gift, coins, store, card, bolt, heart, sparkles, shield, globe, headset, lightning, verified, tag, phone, wallet, mail, lock, search, cursor, chat, rocket) or any emoji. Text, URL and colour are optional; a card without a URL is not a link.</p>';
			html += '<hr><h3>Cards</h3>';
			html += sectionInput('Columns desktop', 'content.columns_d', c.columns_d ?? 4, 'number', 'min="2" max="4"');
			html += sectionInput('Columns mobile', 'content.columns_m', c.columns_m ?? 2, 'number', 'min="1" max="2"');
			html += sectionInput('Icon tile size px', 'content.icon_size', c.icon_size ?? 44, 'number', 'min="36" max="80"');
			html += sectionInput('Card background', 'content.card_bg', c.card_bg || '#ffffff', 'text', 'maxlength="7"');
			html += sectionInput('Card border', 'content.card_border', c.card_border || '#e8e6f7', 'text', 'maxlength="7"');
			html += sectionInput('Card title color', 'content.card_title', c.card_title || '#111827', 'text', 'maxlength="7"');
			html += sectionInput('Card text color', 'content.card_text', c.card_text || '#6b7280', 'text', 'maxlength="7"');
			html += sectionInput('Card radius', 'content.card_radius', c.card_radius ?? 14, 'number', 'min="8" max="48"');
			html += sectionInput('Card gap', 'content.card_gap', c.card_gap ?? 12, 'number', 'min="0" max="40"');
			html += '<hr><h3>Button</h3>';
			html += sectionInput('Button text — blank hides it', 'content.button_text', c.button_text || '');
			html += sectionInput('Button URL', 'content.button_url', c.button_url || '', 'url');
			html += sectionInput('Button gradient start', 'content.button_start', c.button_start || '#5b5bd6', 'text', 'maxlength="7"');
			html += sectionInput('Button gradient end', 'content.button_end', c.button_end || '#e0407f', 'text', 'maxlength="7"');

		} else if (section.type === 'bon_kliyan') {
			html += selectField('Layout', 'content.layout', c.layout || 'auto', [['auto','Auto — text left / cards right on desktop, stacked on phones'],['stack','Stacked everywhere (reference popup look)']]);
			html += selectField('Text alignment (stacked layout and phones)', 'content.align', c.align || 'center', [['center','Center'],['left','Left']]);
			html += sectionInput('Eyebrow', 'content.eyebrow', c.eyebrow ?? 'Programme Bon Kliyan');
			html += sectionInput('Eyebrow icon token — blank = none', 'content.eyebrow_icon', c.eyebrow_icon ?? 'crown');
			html += sectionInput('Title', 'content.title', c.title ?? 'Bon kliyan merite plis.');
			html += sectionTextarea('Subtitle', 'content.subtitle', c.subtitle ?? '');
			html += selectField('Title weight', 'content.title_weight', c.title_weight ?? 600, [['400','Regular'],['500','Medium'],['600','Semi-bold'],['700','Bold'],['800','Extra bold']]);
			html += sectionInput('Title size desktop', 'content.title_size_d', c.title_size_d ?? 46, 'number', 'min="20" max="72"');
			html += sectionInput('Title size tablet', 'content.title_size_t', c.title_size_t ?? 40, 'number', 'min="20" max="64"');
			html += sectionInput('Title size mobile', 'content.title_size_m', c.title_size_m ?? 34, 'number', 'min="18" max="52"');
			html += '<hr><h3>Buttons</h3>';
			html += sectionInput('Button text — blank hides it', 'content.button_text', c.button_text ?? 'Découvrir le programme');
			html += sectionInput('Button URL', 'content.button_url', c.button_url ?? '/bon-kliyan/', 'url');
			html += sectionInput('Second (ghost) button text — optional', 'content.button_2_text', c.button_2_text || '');
			html += sectionInput('Second button URL', 'content.button_2_url', c.button_2_url || '', 'url');
			html += sectionTextarea('Perks — icon|title|text, one per line (up to 6, optional)', 'content.perks', c.perks || '');
			html += '<p class="description">Icon tokens: crown, star, trophy, ticket, percent, diamond, gift, sparkles, heart, bolt, shield, wallet, coins, gamepad, tv, headset — or any emoji. Leave empty to match the reference popup.</p>';
			html += '<hr><h3>Panel colours</h3>';
			html += sectionInput('Panel background', 'content.panel_bg', c.panel_bg || '#12131f', 'text', 'maxlength="7"');
			html += sectionInput('Panel border', 'content.panel_border', c.panel_border || '#2a2b45', 'text', 'maxlength="7"');
			html += sectionInput('Panel radius', 'content.panel_radius', c.panel_radius ?? 30, 'number', 'min="8" max="48"');
			html += sectionInput('Glow colour (top-right light)', 'content.glow_color', c.glow_color || '#6a4dff', 'text', 'maxlength="7"');
			html += sectionInput('Glow strength %', 'content.glow_strength', c.glow_strength ?? 60, 'number', 'min="0" max="100"');
			html += checkbox('Dot grid pattern', 'content.pattern_enabled', c.pattern_enabled !== false && c.pattern_enabled !== 0);
			html += sectionInput('Eyebrow colour', 'content.eyebrow_color', c.eyebrow_color || '#d6ff3f', 'text', 'maxlength="7"');
			html += sectionInput('Title colour', 'content.title_color', c.title_color || '#ffffff', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle colour', 'content.subtitle_color', c.subtitle_color || '#a9adc4', 'text', 'maxlength="7"');
			html += sectionInput('Button background', 'content.button_bg', c.button_bg || '#d6ff3f', 'text', 'maxlength="7"');
			html += sectionInput('Button text colour', 'content.button_text_color', c.button_text_color || '#12131f', 'text', 'maxlength="7"');
			html += '<hr><h3>Visual</h3>';
			html += selectField('Visual', 'content.visual', c.visual || 'cards', [['cards','Floating membership cards (reference)'],['image','Media-library image'],['none','No visual — text only']]);
			html += imageField(c.visual_image_id || 0, 'Image (when Visual = image)', 'content.visual_image_id');
			html += sectionInput('Front card eyebrow', 'content.card_eyebrow', c.card_eyebrow ?? 'Programme');
			html += sectionInput('Front card title', 'content.card_title', c.card_title ?? 'Bon Kliyan');
			html += sectionInput('Front card icon token — blank = none', 'content.card_icon', c.card_icon ?? 'crown');
			html += sectionInput('Front card background', 'content.card_bg', c.card_bg || '#1b1c2e', 'text', 'maxlength="7"');
			html += sectionInput('Front card text colour', 'content.card_text_color', c.card_text_color || '#ffffff', 'text', 'maxlength="7"');
			html += sectionInput('Card accent (eyebrow, icon, badge, rings)', 'content.card_accent', c.card_accent || '#d6ff3f', 'text', 'maxlength="7"');
			html += sectionInput('Back card text', 'content.card_back_text', c.card_back_text ?? 'Delicat');
			html += sectionInput('Back card background', 'content.card_back_bg', c.card_back_bg || '#6f5cff', 'text', 'maxlength="7"');
			html += checkbox('Floating badge', 'content.badge_enabled', c.badge_enabled !== false && c.badge_enabled !== 0);
			html += sectionInput('Badge icon token', 'content.badge_icon', c.badge_icon ?? 'star');
			html += checkbox('Concentric rings behind the cards', 'content.rings_enabled', c.rings_enabled !== false && c.rings_enabled !== 0);
			html += checkbox('Smooth floating motion on the cards and badge (CSS only, respects reduced motion)', 'content.motion_enabled', c.motion_enabled !== false && c.motion_enabled !== 0);

		} else if (section.type === 'newsletter') {
			html += sectionInput('Icon token', 'content.icon', c.icon || 'mail');
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionTextarea('Text', 'content.text', c.text || '');
			html += sectionInput('Email placeholder', 'content.placeholder', c.placeholder || 'votre@email.com');
			html += sectionInput('Button text', 'content.button_text', c.button_text || "S'ABONNER");
			html += sectionInput('Trusted form action URL — blank = safe visual preview only', 'content.action_url', c.action_url || '', 'url');
			html += '<p class="description">For security, Builder does not create a public subscriber-storage endpoint. Configure a trusted HTTPS newsletter endpoint when ready.</p>';
			html += '<hr><h3>Panel design</h3>';
			html += sectionInput('Panel background', 'content.panel_bg', c.panel_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Panel border', 'content.panel_border', c.panel_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Text color', 'content.text_color', c.text_color || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Gradient start', 'content.accent_start', c.accent_start || '#5964ff', 'text', 'maxlength="7"');
			html += sectionInput('Gradient end', 'content.accent_end', c.accent_end || '#ee3ba8', 'text', 'maxlength="7"');
			html += sectionInput('Panel radius', 'content.card_radius', c.card_radius ?? 28, 'number', 'min="8" max="48"');

		} else if (section.type === 'trust_strip') {
			html += sectionTextarea('Trust items — icon|title|subtitle, maximum 3', 'content.items', c.items || '');
			html += sectionInput('Panel background', 'content.panel_bg', c.panel_bg || '#0a0a23', 'text', 'maxlength="7"');
			html += sectionInput('Panel border', 'content.panel_border', c.panel_border || '#25264d', 'text', 'maxlength="7"');
			html += sectionInput('Title color', 'content.title_color', c.title_color || '#f8f8ff', 'text', 'maxlength="7"');
			html += sectionInput('Subtitle color', 'content.subtitle_color', c.subtitle_color || '#989bad', 'text', 'maxlength="7"');
			html += sectionInput('Accent 1', 'content.accent_1', c.accent_1 || '#5964ff', 'text', 'maxlength="7"');
			html += sectionInput('Accent 2', 'content.accent_2', c.accent_2 || '#56d8f1', 'text', 'maxlength="7"');
			html += sectionInput('Accent 3', 'content.accent_3', c.accent_3 || '#ee5abd', 'text', 'maxlength="7"');
			html += sectionInput('Panel radius', 'content.card_radius', c.card_radius ?? 28, 'number', 'min="8" max="48"');
			html += '<hr><h3>Domain trust</h3>';
			html += sectionInput('Domain label', 'content.domain_label', c.domain_label || '');
			html += sectionInput('Domain URL', 'content.domain_url', c.domain_url || '', 'url');
			html += sectionInput('Online status color', 'content.domain_status', c.domain_status || '#2fa968', 'text', 'maxlength="7"');
			html += sectionTextarea('Footer text', 'content.footer_text', c.footer_text || '');

		} else if (section.type === 'category_chips') {
			html += selectField('Category layout', 'content.style', c.style || 'rail', [['rail','Compact rail'],['grid','Parcourir grid']]);
			html += sectionInput('Eyebrow / small label', 'content.eyebrow', c.eyebrow || '');
			html += sectionInput('Title', 'content.title', c.title || '');
			html += sectionInput('Subtitle', 'content.subtitle', c.subtitle || '');
			html += sectionTextarea('Items — icon|label|URL|meta, one per line', 'content.items', c.items || '');
			html += sectionInput('Active item index (-1 = none)', 'content.active_index', c.active_index ?? 0, 'number', 'min="-1" max="20"');
		} else if (section.type === 'spacer') {
			html += sectionInput('Desktop height', 'content.height_desktop', c.height_desktop, 'number', 'min="0" max="240"');
			html += sectionInput('Tablet height', 'content.height_tablet', c.height_tablet, 'number', 'min="0" max="200"');
			html += sectionInput('Mobile height', 'content.height_mobile', c.height_mobile, 'number', 'min="0" max="160"');
		}

		if (section.type !== 'spacer') {
			html += '<hr><h3>Responsive layout</h3>';
			html += selectField('Max width', 'max_width', section.max_width, [['960','960px'],['1080','1080px'],['1200','1200px'],['1320','1320px'],['1440','1440px'],['full','Full width']]);
			html += sectionInput('Background', 'background', section.background || '', 'text', 'placeholder="#ffffff" maxlength="7"');
			html += sectionInput('Desktop padding', 'spacing.desktop', section.spacing?.desktop ?? 24, 'number', 'min="0" max="160"');
			html += sectionInput('Tablet padding', 'spacing.tablet', section.spacing?.tablet ?? 20, 'number', 'min="0" max="140"');
			html += sectionInput('Mobile padding', 'spacing.mobile', section.spacing?.mobile ?? 16, 'number', 'min="0" max="120"');
			html += '<div class="delicat-visibility-grid">';
			html += checkbox('Desktop', 'visibility.desktop', section.visibility?.desktop !== false);
			html += checkbox('Tablet', 'visibility.tablet', section.visibility?.tablet !== false);
			html += checkbox('Mobile', 'visibility.mobile', section.visibility?.mobile !== false);
			html += '</div>';
		}

		inspector.innerHTML = html;
		bindInspector(section);
	};

	const imageField = (id, label = 'WordPress image ID', path = 'content.image_id') => `
		<div class="delicat-inspector-field">
			<span>${esc(label)}</span>
			<div class="delicat-media-row">
				<input type="number" data-path="${esc(path)}" value="${esc(id)}" min="0">
				<button type="button" class="button" data-delicat-media data-media-path="${esc(path)}">Choose</button>
			</div>
		</div>`;

	const setPath = (object, path, value) => {
		const parts = path.split('.');
		let node = object;
		while (parts.length > 1) {
			const key = parts.shift();
			if (!node[key] || typeof node[key] !== 'object') node[key] = {};
			node = node[key];
		}
		node[parts[0]] = value;
	};

	const bindInspector = (section) => {
		inspector.querySelectorAll('[data-path]').forEach((input) => {
			const update = () => {
				let value = input.type === 'checkbox' ? input.checked : input.value;
				if (input.type === 'number') value = Number(value || 0);
				setPath(section, input.dataset.path, value);
				sync();
				renderCanvas();
			};
			input.addEventListener(input.tagName === 'SELECT' || input.type === 'checkbox' ? 'change' : 'input', update);
		});

		inspector.querySelectorAll('[data-delicat-media]').forEach((button) => {
			button.addEventListener('click', () => {
				if (!window.wp?.media) return;
				const path = button.dataset.mediaPath || 'content.image_id';
				const frame = wp.media({title: 'Choose image', button: {text: 'Use image'}, multiple: false, library: {type: 'image'}});
				frame.on('select', () => {
					const attachment = frame.state().get('selection').first()?.toJSON();
					if (!attachment?.id) return;
					setPath(section, path, Number(attachment.id));
					renderInspector();
					sync();
				});
				frame.open();
			});
		});
	};

	const render = () => {
		renderCanvas();
		renderInspector();
		sync();
	};

	renderLibrary();
	render();
})();
