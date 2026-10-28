(function () {
    'use strict';

    var form = document.getElementById('dipb-form');
    var shell = document.getElementById('dipb-preview-shell');
    var stage = document.querySelector('.dipb-stage');
    if (!form || !shell || !stage) return;

    var optionName = (window.dipBuilderPro && dipBuilderPro.option) || 'dip_visual_builder_pro';
    var scheduled = false;
    var initialState = {};

    var variableMap = {
        background_color: ['bg', ''],
        gradient_start: ['g1', ''],
        gradient_end: ['g2', ''],
        gradient_angle: ['angle', 'deg'],
        overlay_color: ['overlay_color', ''],
        card_color: ['card_color', ''],
        text_color: ['text', ''],
        muted_color: ['muted', ''],
        accent_color: ['accent', ''],
        accent_hover: ['accent-hover', ''],
        border_color: ['border', ''],
        input_background: ['input-bg', ''],
        input_border: ['input-border', ''],
        input_text: ['input-text', ''],
        provider_background: ['provider-bg', ''],
        provider_text: ['provider-text', ''],
        radius: ['radius', 'px'],
        input_radius: ['input-radius', 'px'],
        button_radius: ['button-radius', 'px'],
        max_width: ['width', 'px'],
        padding: ['padding', 'px'],
        card_border_width: ['border-width', 'px'],
        card_blur: ['blur', 'px'],
        heading_size: ['heading', 'px'],
        body_size: ['body', 'px'],
        font_weight: ['weight', ''],
        logo_size: ['logo-size', 'px'],
        button_height: ['button-height', 'px'],
        field_gap: ['field-gap', 'px'],
        shell_min_height: ['shell-height', 'px'],
        card_section_gap: ['section-gap', 'px'],
        provider_gap: ['provider-gap', 'px'],
        divider_gap: ['divider-gap', 'px'],
        input_height: ['input-height', 'px'],
        provider_border_width: ['provider-border-width', 'px'],
        provider_icon_size: ['provider-icon-size', 'px'],
        button_font_size: ['button-font-size', 'px'],
        link_font_size: ['link-font-size', 'px']
    };

    var presets = {
        'delicat-glass': {
            background: 'gradient', gradient_start: '#07142b', gradient_end: '#123f7a', overlay_color: '#07142b', overlay_opacity: 18,
            card_color: '#ffffff', card_opacity: 92, card_blur: 16, text_color: '#10213b', muted_color: '#667085', accent_color: '#155eef', accent_hover: '#0f4fcf',
            provider_background: '#ffffff', provider_text: '#172033', input_background: '#ffffff', input_border: '#d8dee9', input_text: '#10213b', border_color: '#dfe7f2', shadow: 'soft'
        },
        'delicat-dark': {
            background: 'gradient', gradient_start: '#030b18', gradient_end: '#142b52', overlay_color: '#020817', overlay_opacity: 24,
            card_color: '#0d1b32', card_opacity: 96, card_blur: 12, text_color: '#ffffff', muted_color: '#a9b6ca', accent_color: '#2f80ff', accent_hover: '#176ce8',
            provider_background: '#122541', provider_text: '#ffffff', input_background: '#122541', input_border: '#29405f', input_text: '#ffffff', border_color: '#29405f', shadow: 'strong'
        },
        minimal: {
            background: 'solid', background_color: '#f5f7fb', overlay_opacity: 0, card_color: '#ffffff', card_opacity: 100, card_blur: 0,
            text_color: '#172033', muted_color: '#667085', accent_color: '#111827', accent_hover: '#000000', provider_background: '#ffffff', provider_text: '#172033',
            input_background: '#ffffff', input_border: '#d0d5dd', input_text: '#172033', border_color: '#e5e7eb', radius: 18, shadow: 'none'
        },
        corporate: {
            background: 'gradient', gradient_start: '#eaf2ff', gradient_end: '#f7faff', overlay_opacity: 0, card_color: '#ffffff', card_opacity: 100,
            text_color: '#12345b', muted_color: '#60758d', accent_color: '#0b63ce', accent_hover: '#084fa7', provider_background: '#ffffff', provider_text: '#12345b',
            input_background: '#ffffff', input_border: '#cdd9e7', input_text: '#12345b', border_color: '#d8e2ee', radius: 20, shadow: 'soft'
        },
        gaming: {
            background: 'gradient', gradient_start: '#09021c', gradient_end: '#27105f', overlay_color: '#05000f', overlay_opacity: 18,
            card_color: '#120b2d', card_opacity: 94, card_blur: 14, text_color: '#ffffff', muted_color: '#c6b9e7', accent_color: '#9b5cff', accent_hover: '#7c3aed',
            provider_background: '#1b1240', provider_text: '#ffffff', input_background: '#1b1240', input_border: '#6ee7ff', input_text: '#ffffff', border_color: '#6ee7ff', shadow: 'glow'
        },
        premium: {
            background: 'gradient', gradient_start: '#17110a', gradient_end: '#4b3215', overlay_color: '#0b0804', overlay_opacity: 20,
            card_color: '#fffaf0', card_opacity: 96, text_color: '#2d2112', muted_color: '#77654d', accent_color: '#b7791f', accent_hover: '#966114',
            provider_background: '#fffdf8', provider_text: '#2d2112', input_background: '#fffdf8', input_border: '#e5d5b6', input_text: '#2d2112', border_color: '#e7d3ab', shadow: 'strong'
        },
        'soft-light': {
            background: 'gradient', gradient_start: '#eef6ff', gradient_end: '#f7efff', overlay_opacity: 0, card_color: '#ffffff', card_opacity: 96,
            text_color: '#25324a', muted_color: '#728098', accent_color: '#6d5dfc', accent_hover: '#5947e8', provider_background: '#ffffff', provider_text: '#25324a',
            input_background: '#fbfcff', input_border: '#dfe4ee', input_text: '#25324a', border_color: '#e4e8f0', shadow: 'soft'
        },
        midnight: {
            background: 'solid', background_color: '#050a13', overlay_opacity: 0, card_color: '#101a2a', card_opacity: 98, text_color: '#f8fafc', muted_color: '#94a3b8',
            accent_color: '#38bdf8', accent_hover: '#0ea5e9', provider_background: '#172338', provider_text: '#f8fafc', input_background: '#172338', input_border: '#334155', input_text: '#f8fafc', border_color: '#26364d', shadow: 'strong'
        },
        aurora: {
            background: 'gradient', gradient_start: '#052e2b', gradient_end: '#312e81', overlay_color: '#021a19', overlay_opacity: 12, card_color: '#081f26', card_opacity: 90,
            card_blur: 18, text_color: '#ecfeff', muted_color: '#b7d8d8', accent_color: '#22c55e', accent_hover: '#16a34a', provider_background: '#0e3037', provider_text: '#ecfeff',
            input_background: '#0e3037', input_border: '#2f6d70', input_text: '#ecfeff', border_color: '#2f6d70', shadow: 'glow'
        },
        commerce: {
            background: 'gradient', gradient_start: '#eef6ff', gradient_end: '#dbeafe', overlay_opacity: 0, card_color: '#ffffff', card_opacity: 100,
            text_color: '#10213b', muted_color: '#607089', accent_color: '#0f63c8', accent_hover: '#0b4f9f', provider_background: '#ffffff', provider_text: '#10213b',
            input_background: '#ffffff', input_border: '#cfdaea', input_text: '#10213b', border_color: '#dbe4ef', radius: 22, shadow: 'soft'
        }
    };

    function allFields() {
        return Array.prototype.slice.call(form.querySelectorAll('[name^="' + optionName + '["]'));
    }

    function field(name) {
        return form.querySelector('[name="' + optionName + '[' + name + ']"]');
    }

    function value(name) {
        var el = field(name);
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? 'yes' : 'no';
        return el.value;
    }

    function setField(name, newValue) {
        var el = field(name);
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = newValue === true || newValue === 'yes' || newValue === 'on' || newValue === 1;
        } else {
            el.value = newValue;
        }
    }

    function rgba(hex, opacity) {
        var clean = String(hex || '#ffffff').replace('#', '');
        if (!/^[0-9a-fA-F]{6}$/.test(clean)) clean = 'ffffff';
        var alpha = Math.max(0, Math.min(100, Number(opacity) || 0)) / 100;
        return 'rgba(' + parseInt(clean.slice(0, 2), 16) + ',' + parseInt(clean.slice(2, 4), 16) + ',' + parseInt(clean.slice(4, 6), 16) + ',' + alpha + ')';
    }

    function safeCssUrl(url) {
        var cleaned = String(url || '').replace(/["'()\\\n\r]/g, '');
        return cleaned ? 'url("' + cleaned + '")' : 'none';
    }

    function setVar(name, val) {
        shell.style.setProperty('--dipb-' + name, val);
    }

    function syncOutputs() {
        document.querySelectorAll('[data-output-for]').forEach(function (output) {
            var key = output.getAttribute('data-output-for');
            var input = field(key);
            if (!input) return;
            var unit = 'px';
            if (key === 'overlay_opacity' || key === 'card_opacity') unit = '%';
            if (key === 'gradient_angle') unit = '°';
            if (key === 'font_weight') unit = '';
            output.textContent = input.value + unit;
        });
        document.querySelectorAll('[data-color-code]').forEach(function (code) {
            var input = code.parentNode && code.parentNode.querySelector('input[type="color"]');
            if (input) code.textContent = input.value.toUpperCase();
        });
    }

    function draw() {
        scheduled = false;

        shell.className = [
            'dip-auth-shell',
            'dipb-bg-' + value('background'),
            'dipb-layout-' + value('layout'),
            'dipb-preset-' + value('preset'),
            'dipb-motion-' + value('motion'),
            'dipb-align-' + value('text_align'),
            value('mobile_bottom_sheet') === 'yes' ? 'dipb-mobile-sheet' : ''
        ].join(' ').replace(/\s+/g, ' ').trim();
        shell.setAttribute('data-bg-position', value('background_position') || 'center');

        Object.keys(variableMap).forEach(function (key) {
            var map = variableMap[key];
            setVar(map[0], value(key) + map[1]);
        });
        setVar('bg-image', safeCssUrl(value('background_image')));
        setVar('overlay', rgba(value('overlay_color'), value('overlay_opacity')));
        setVar('card', rgba(value('card_color'), value('card_opacity')));

        var title = document.getElementById('dipb-title');
        var subtitle = document.getElementById('dipb-subtitle');
        if (title) title.textContent = value('title') || 'Bienvenue';
        if (subtitle) subtitle.textContent = value('subtitle') || '';

        var brand = document.getElementById('dipb-brand');
        var logo = value('logo_url');
        if (brand) {
            brand.textContent = '';
            brand.classList.toggle('has-image', !!logo);
            if (logo) {
                var img = document.createElement('img');
                img.src = logo;
                img.alt = '';
                img.addEventListener('error', function () {
                    brand.classList.remove('has-image');
                    brand.textContent = 'D';
                }, { once: true });
                brand.appendChild(img);
            } else {
                brand.textContent = 'D';
            }
        }

        var nativeVisible = value('show_native') === 'yes';
        shell.querySelectorAll('.dip-auth-divider, input[type="email"], input[type="password"], .dip-auth-primary').forEach(function (el) {
            el.hidden = !nativeVisible;
        });
        var remember = shell.querySelector('.dipb-preview-remember');
        if (remember) remember.hidden = !(nativeVisible && value('show_remember') === 'yes');

        var registerLink = shell.querySelector('[data-preview-link="register"]');
        var lostLink = shell.querySelector('[data-preview-link="lost"]');
        if (registerLink) registerLink.hidden = value('show_register') !== 'yes' || shell.getAttribute('data-registration-available') !== '1';
        if (lostLink) lostLink.hidden = value('show_lost_password') !== 'yes';

        var card = shell.querySelector('.dip-auth-card');
        if (card) card.className = 'dip-auth-card dipb-shadow-' + value('shadow');

        syncOutputs();
        shell.setAttribute('data-preview-ready', 'true');
        var cardHeight = card ? Math.ceil(card.getBoundingClientRect().height) : 0;
        var requestedHeight = Number(value('shell_min_height')) || 620;
        shell.style.minHeight = Math.max(requestedHeight, cardHeight + 48) + 'px';
        stage.style.setProperty('--dipb-preview-content-height', Math.max(requestedHeight, cardHeight + 48) + 'px');
    }

    function scheduleDraw() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(draw);
    }

    allFields().forEach(function (el) {
        initialState[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        el.addEventListener('input', scheduleDraw);
        el.addEventListener('change', scheduleDraw);
    });

    var presetField = field('preset');
    if (presetField) {
        presetField.addEventListener('change', function () {
            var config = presets[presetField.value];
            if (config) {
                Object.keys(config).forEach(function (key) { setField(key, config[key]); });
            }
            scheduleDraw();
        });
    }

    document.querySelectorAll('[data-builder-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            var panelName = button.getAttribute('data-builder-tab');
            document.querySelectorAll('[data-builder-tab]').forEach(function (item) { item.classList.remove('is-active'); });
            document.querySelectorAll('[data-builder-panel]').forEach(function (panel) { panel.classList.remove('is-active'); });
            button.classList.add('is-active');
            var target = document.querySelector('[data-builder-panel="' + panelName + '"]');
            if (target) target.classList.add('is-active');
        });
    });

    document.querySelectorAll('[data-device]').forEach(function (button) {
        button.addEventListener('click', function () {
            var device = button.getAttribute('data-device') || 'desktop';
            document.querySelectorAll('[data-device]').forEach(function (item) { item.classList.remove('is-active'); });
            button.classList.add('is-active');
            stage.setAttribute('data-preview-device', device);
            stage.scrollTop = 0;
            stage.scrollLeft = 0;
            scheduleDraw();
        });
    });

    document.querySelectorAll('.dipb-media-select').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.wp || !wp.media) return;
            var frame = wp.media({
                title: (window.dipBuilderPro || {}).chooseImage || 'Choisir une image',
                button: { text: (window.dipBuilderPro || {}).useImage || 'Utiliser cette image' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var input = field(button.getAttribute('data-target'));
                if (!input) return;
                input.value = attachment.url || '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            frame.open();
        });
    });

    var copy = document.querySelector('.dipb-copy-shortcode');
    if (copy) {
        copy.addEventListener('click', function () {
            var done = function () {
                var original = copy.textContent;
                copy.textContent = 'Copié';
                setTimeout(function () { copy.textContent = original; }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText('[delicat_auth_panel]').then(done).catch(function () {});
            } else {
                var textarea = document.createElement('textarea');
                textarea.value = '[delicat_auth_panel]';
                document.body.appendChild(textarea);
                textarea.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                textarea.remove();
            }
        });
    }

    var reset = document.getElementById('dipb-reset-preview');
    if (reset) {
        reset.addEventListener('click', function () {
            allFields().forEach(function (el) {
                if (!Object.prototype.hasOwnProperty.call(initialState, el.name)) return;
                if (el.type === 'checkbox') el.checked = !!initialState[el.name];
                else el.value = initialState[el.name];
            });
            scheduleDraw();
        });
    }

    window.addEventListener('load', scheduleDraw);
    scheduleDraw();
})();
