(function($){
    'use strict';
    var $form = $('#desp-main-form');
    var $frame = $('#desp-preview-frame');
    var $picker = $('#desp-email-picker');
    var timer = null;
    var previewTheme = 'current';
    var isDirty = false;
    if (!$form.length || !$frame.length || typeof DIPES_ADMIN === 'undefined') return;

    $('.desp-color').wpColorPicker({
        change: function(){ schedulePreview(); },
        clear: function(){ schedulePreview(); }
    });

    function activeEmailPanel(){
        return $('.desp-email-panel.is-active');
    }

    function collect($scope, attr){
        var data = {};
        $scope.find('[' + attr + ']').each(function(){
            var $el = $(this), key = $el.attr(attr);
            if (!key) return;
            if ($el.is(':radio')) {
                if ($el.is(':checked')) data[key] = $el.val();
                return;
            }
            if ($el.is(':checkbox')) {
                data[key] = $el.is(':checked') ? 'yes' : 'no';
                return;
            }
            data[key] = $el.val();
        });
        return data;
    }

    function applyPreset(key, persistSelection){
        var preset = DIPES_ADMIN.presets && DIPES_ADMIN.presets[key];
        if (!preset || !preset.tokens) return;
        Object.keys(preset.tokens).forEach(function(token){
            var $input = $('[data-desp-global="' + token + '"]');
            if (!$input.length) return;
            $input.val(preset.tokens[token]);
            if ($input.hasClass('desp-color')) $input.wpColorPicker('color', preset.tokens[token]);
        });
        if (persistSelection) {
            var $radio = $('.desp-preset input[type="radio"][value="' + key + '"]');
            if ($radio.length) {
                $('.desp-preset').removeClass('is-active');
                $radio.prop('checked', true).closest('.desp-preset').addClass('is-active');
            }
        }
        schedulePreview();
    }

    function previewUrl(){
        var type = $picker.val() || 'customer_processing_order';
        var params = new URLSearchParams();
        params.set('action', 'dipes_email_preview');
        params.set('_wpnonce', DIPES_ADMIN.previewNonce);
        params.set('type', type);
        params.set('_t', Date.now());
        var g = collect($form, 'data-desp-global');
        var e = collect(activeEmailPanel(), 'data-desp-email');
        if (previewTheme === 'light' && DIPES_ADMIN.presets && DIPES_ADMIN.presets.glass_light) {
            g = Object.assign({}, g, DIPES_ADMIN.presets.glass_light.tokens || {}, { appearance_mode: 'light' });
        } else if (previewTheme === 'dark' && DIPES_ADMIN.presets && DIPES_ADMIN.presets.glass_dark) {
            g = Object.assign({}, g, DIPES_ADMIN.presets.glass_dark.tokens || {}, { appearance_mode: 'dark' });
        }
        Object.keys(g).forEach(function(k){ params.set('g[' + k + ']', g[k]); });
        Object.keys(e).forEach(function(k){ params.set('e[' + k + ']', e[k]); });
        return DIPES_ADMIN.previewUrl + '?' + params.toString();
    }

    function refreshPreview(){
        $('#desp-preview-status').text('Mise à jour…');
        $frame.attr('src', previewUrl());
    }
    function schedulePreview(){
        window.clearTimeout(timer);
        timer = window.setTimeout(refreshPreview, 260);
    }
    $frame.on('load', function(){ $('#desp-preview-status').text('Aperçu en direct'); });

    $form.on('input change', '[data-desp-global],[data-desp-email]', function(){
        isDirty = true;
        $('.desp-topbar').addClass('is-dirty');
        $('#desp-preview-status').text('Modifications non enregistrées');
        schedulePreview();
    });
    $form.on('submit', function(){ isDirty = false; $('.desp-topbar').removeClass('is-dirty'); });
    $('#desp-refresh-preview').on('click', refreshPreview);

    $('.desp-tabs button').on('click', function(){
        var tab = $(this).data('desp-tab');
        $('.desp-tabs button').removeClass('is-active');
        $(this).addClass('is-active');
        $('.desp-tab-panel').removeClass('is-active');
        $('.desp-tab-panel[data-desp-panel="' + tab + '"]').addClass('is-active');
    });

    $picker.on('change', function(){
        var id = $(this).val();
        $('.desp-email-panel').removeClass('is-active');
        $('.desp-email-panel[data-email-panel="' + id + '"]').addClass('is-active');
        $('#desp-test-type').val(id);
        refreshPreview();
    });

    $('.desp-preset input[type="radio"]').on('change', function(){
        var key = $(this).val();
        $('.desp-preset').removeClass('is-active');
        $(this).closest('.desp-preset').addClass('is-active');
        applyPreset(key, false);
    });

    $('.desp-quick-theme').on('click', function(){
        var key = $(this).data('apply-preset');
        applyPreset(key, true);
        $('[data-desp-global="appearance_mode"]').val('auto');
        isDirty = true;
        $('.desp-topbar').addClass('is-dirty');
    });

    $('[data-range-key]').on('input change', function(){
        var key = $(this).data('range-key');
        $('[data-range-output="' + key + '"]').text($(this).val());
    });

    $('.desp-sortable').sortable({
        handle: '.dashicons-menu',
        placeholder: 'desp-sort-placeholder',
        update: function(){
            var $list = $(this), values = [];
            $list.children('li').each(function(){ values.push($(this).data('block')); });
            $list.siblings('.desp-order-input').val(values.join(','));
            schedulePreview();
        }
    });

    $('.desp-layout-presets button').on('click', function(){
        var layouts = {
            premium: ['intro','notice','summary','progress','cta','order_details','order_meta','customer_details','additional','custom'],
            compact: ['intro','summary','order_details','cta','customer_details','notice','order_meta','additional','progress','custom'],
            details_first: ['intro','order_details','summary','cta','customer_details','order_meta','notice','progress','additional','custom']
        };
        var layout = layouts[$(this).data('block-layout')];
        if (!layout) return;
        var $card = $(this).closest('.desp-card');
        var $list = $card.find('.desp-sortable');
        layout.forEach(function(block){
            var $item = $list.children('[data-block="' + block + '"]');
            if ($item.length) $list.append($item);
        });
        var values = [];
        $list.children('li').each(function(){ values.push($(this).data('block')); });
        $card.find('.desp-order-input').val(values.join(',')).trigger('change');
        $card.find('.desp-layout-presets button').removeClass('is-active');
        $(this).addClass('is-active');
        isDirty = true;
        $('.desp-topbar').addClass('is-dirty');
        schedulePreview();
    });

    $('.desp-device-buttons button').on('click', function(){
        $('.desp-device-buttons button').removeClass('is-active');
        $(this).addClass('is-active');
        $('.desp-preview-stage').toggleClass('is-mobile', $(this).data('device') === 'mobile');
    });

    $('.desp-preview-theme-buttons button').on('click', function(){
        $('.desp-preview-theme-buttons button').removeClass('is-active');
        $(this).addClass('is-active');
        previewTheme = $(this).data('preview-theme') || 'current';
        refreshPreview();
    });

    window.addEventListener('beforeunload', function(e){
        if (!isDirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    var mediaFrame;
    $('#desp-logo-pick').on('click', function(e){
        e.preventDefault();
        if (mediaFrame) { mediaFrame.open(); return; }
        mediaFrame = wp.media({ title: 'Choisir le logo des e-mails', button: { text: 'Utiliser ce logo' }, multiple: false, library: { type: 'image' } });
        mediaFrame.on('select', function(){
            var item = mediaFrame.state().get('selection').first().toJSON();
            $('#desp-logo-url').val(item.url).trigger('input');
        });
        mediaFrame.open();
    });

    refreshPreview();
})(jQuery);
