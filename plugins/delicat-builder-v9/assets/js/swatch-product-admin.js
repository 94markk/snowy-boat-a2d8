(function () {
  'use strict';

  var cfg = window.DelicatSwatchProductAdmin || {};
  var fieldId = String(cfg.fieldId || '_ddsw_product_preset');
  var panelId = 'delicat_swatches_product_data';
  var tabClass = 'delicat_swatches_options';

  function productType() {
    var select = document.getElementById('product-type');
    return select ? String(select.value || '') : '';
  }

  function getPanelWrap() {
    var box = document.getElementById('woocommerce-product-data');
    if (!box) return null;
    return box.querySelector('.panel-wrap.product_data, .panel-wrap') || box;
  }

  function makeSelect(id, name, value) {
    var select = document.createElement('select');
    select.id = id;
    if (name) select.name = name;
    select.className = 'select short';

    Object.keys(cfg.options || {}).forEach(function (key) {
      var option = document.createElement('option');
      option.value = key;
      option.textContent = String(cfg.options[key]);
      if (String(value || 'default') === key) option.selected = true;
      select.appendChild(option);
    });
    return select;
  }

  function ensurePanel() {
    var panel = document.getElementById(panelId);
    if (panel) return panel;

    var wrap = getPanelWrap();
    if (!wrap || !cfg.options) return null;

    panel = document.createElement('div');
    panel.id = panelId;
    panel.className = 'panel woocommerce_options_panel hidden';

    var group = document.createElement('div');
    group.className = 'options_group delicat-swatch-product-settings';

    var heading = document.createElement('div');
    heading.className = 'delicat-swatch-product-heading';
    heading.innerHTML = '<strong>Swatch Studio</strong><span>Style des variations pour ce produit</span>';

    var row = document.createElement('p');
    row.className = 'form-field delicat-swatch-preset-field show_if_variable';

    var label = document.createElement('label');
    label.setAttribute('for', fieldId);
    label.textContent = 'Preset du produit';

    var select = makeSelect(fieldId, fieldId, cfg.value || 'default');

    var description = document.createElement('span');
    description.className = 'description delicat-swatch-product-description';
    description.textContent = String(cfg.description || 'Choisissez le style de variations pour ce produit.');

    row.appendChild(label);
    row.appendChild(select);
    row.appendChild(description);

    var help = document.createElement('div');
    help.className = 'delicat-swatch-product-help';
    help.innerHTML = '<div><b>Style global</b><span>Utilise le style Swatch Studio par défaut.</span></div>' +
      '<div><b>Delicat ABONNEMENT premium</b><span>Cartes abonnement premium pour Netflix, streaming et services similaires.</span></div>';

    group.appendChild(heading);
    group.appendChild(row);
    group.appendChild(help);
    panel.appendChild(group);
    wrap.appendChild(panel);
    return panel;
  }

  function ensureTab() {
    var box = document.getElementById('woocommerce-product-data');
    if (!box) return null;
    var tabs = box.querySelector('ul.product_data_tabs, ul.wc-tabs');
    if (!tabs) return null;

    var tab = tabs.querySelector('li.' + tabClass + ', li.delicat_swatches_tab');
    if (!tab) {
      tab = document.createElement('li');
      tab.className = tabClass + ' delicat_swatches_tab show_if_variable';
      var link = document.createElement('a');
      link.href = '#' + panelId;
      link.innerHTML = '<span>Swatch Studio</span>';
      tab.appendChild(link);

      var variations = tabs.querySelector('li.variations_options');
      if (variations) tabs.insertBefore(tab, variations);
      else tabs.appendChild(tab);
    }
    return tab;
  }

  function showPanel() {
    var box = document.getElementById('woocommerce-product-data');
    var panel = document.getElementById(panelId);
    if (!box || !panel) return;

    box.querySelectorAll('.woocommerce_options_panel').forEach(function (item) {
      item.style.display = 'none';
      item.classList.add('hidden');
    });
    box.querySelectorAll('ul.product_data_tabs li, ul.wc-tabs li').forEach(function (li) {
      li.classList.remove('active');
    });

    panel.style.display = 'block';
    panel.classList.remove('hidden');
    var tab = box.querySelector('li.' + tabClass + ', li.delicat_swatches_tab');
    if (tab) tab.classList.add('active');
  }

  function bindTab() {
    var tab = ensureTab();
    if (!tab || tab.dataset.delicatBound) return;
    tab.dataset.delicatBound = '1';
    var link = tab.querySelector('a');
    if (!link) return;
    link.addEventListener('click', function (event) {
      event.preventDefault();
      showPanel();
    });
  }

  function syncMirror(primary, mirror) {
    if (!primary || !mirror) return;
    mirror.value = primary.value;
    if (!primary.dataset.delicatMirrorBound) {
      primary.dataset.delicatMirrorBound = '1';
      primary.addEventListener('change', function () {
        mirror.value = primary.value;
      });
    }
    if (!mirror.dataset.delicatPrimaryBound) {
      mirror.dataset.delicatPrimaryBound = '1';
      mirror.addEventListener('change', function () {
        primary.value = mirror.value;
        primary.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  }

  function ensureGeneralMirror() {
    var general = document.getElementById('general_product_data');
    var primary = document.getElementById(fieldId);
    if (!general || !primary || document.getElementById(fieldId + '_general_mirror')) return;

    var group = document.createElement('div');
    group.className = 'options_group delicat-swatch-general-mirror';

    var row = document.createElement('p');
    row.className = 'form-field delicat-swatch-preset-field show_if_variable';

    var label = document.createElement('label');
    label.setAttribute('for', fieldId + '_general_mirror');
    label.textContent = String(cfg.label || 'Swatch Studio');

    var mirror = makeSelect(fieldId + '_general_mirror', '', primary.value);
    mirror.removeAttribute('name');

    var description = document.createElement('span');
    description.className = 'description delicat-swatch-product-description';
    description.textContent = 'Raccourci synchronisé avec l’onglet Swatch Studio.';

    row.appendChild(label);
    row.appendChild(mirror);
    row.appendChild(description);
    group.appendChild(row);
    general.appendChild(group);
    syncMirror(primary, mirror);
  }

  function toggleUI() {
    var variable = productType() === 'variable';
    var tab = document.querySelector('#woocommerce-product-data li.' + tabClass + ', #woocommerce-product-data li.delicat_swatches_tab');
    if (tab) tab.style.display = variable ? '' : 'none';

    document.querySelectorAll('.delicat-swatch-preset-field, .delicat-swatch-general-mirror').forEach(function (el) {
      el.style.display = variable ? '' : 'none';
    });
  }

  function ensureAll() {
    ensurePanel();
    ensureTab();
    bindTab();
    ensureGeneralMirror();
    toggleUI();
  }

  function boot() {
    ensureAll();

    var type = document.getElementById('product-type');
    if (type && !type.dataset.delicatSwatchBound) {
      type.dataset.delicatSwatchBound = '1';
      type.addEventListener('change', function () {
        window.setTimeout(ensureAll, 0);
        window.setTimeout(ensureAll, 120);
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      if (target.closest('.save-variation-changes, .variations_options, .product_data_tabs, .wc-tabs')) {
        window.setTimeout(ensureAll, 80);
      }
    }, true);

    // WooCommerce or third-party product editors can rebuild the metabox after
    // page ready. A few bounded retries are cheaper and safer than a permanent
    // MutationObserver on a large product edit screen.
    [180, 450, 900, 1600].forEach(function (delay) {
      window.setTimeout(ensureAll, delay);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
