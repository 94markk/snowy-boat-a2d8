/* =============================================================================
   ui.js — the drawer, the sheets and the cart count.
   -----------------------------------------------------------------------------
   Each of these is a <dialog>, so the browser supplies the modal semantics that
   are hard to get right by hand: focus is trapped, Escape closes, the page
   behind is inert, and the backdrop is a real pseudo-element rather than a
   div that has to be kept in sync. The animation is CSS.
   ============================================================================= */

(function () {
  'use strict';

  /* ---------------------------------------------------------------------
     Dialogs
     ------------------------------------------------------------------ */

  function open(dialog) {
    if (!dialog || dialog.open) return;
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      /* No <dialog> support: leave the link to do what it would have done. */
      dialog.setAttribute('open', '');
    }
    /* The page behind a modal must not scroll under it. overflow on <html>
     * rather than <body> so iOS honours it. */
    document.documentElement.style.overflow = 'hidden';
  }

  function close(dialog) {
    if (!dialog || !dialog.open) return;
    dialog.close();
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target.closest) return;

    var opener = target.closest('[data-dlx-open]');
    if (opener) {
      var dialog = document.getElementById(opener.getAttribute('data-dlx-open'));
      if (dialog) {
        event.preventDefault();
        open(dialog);
      }
      return;
    }

    var closer = target.closest('[data-dlx-close]');
    if (closer) {
      event.preventDefault();
      close(closer.closest('dialog'));
    }
  });

  /* A tap on the backdrop closes. The backdrop is outside the dialog's box, so
   * a click whose coordinates fall outside that box is a backdrop click. */
  document.addEventListener('click', function (event) {
    var dialog = event.target;
    if (dialog.tagName !== 'DIALOG' || !dialog.open) return;

    var box = dialog.getBoundingClientRect();
    var inside =
      event.clientX >= box.left && event.clientX <= box.right &&
      event.clientY >= box.top && event.clientY <= box.bottom;

    if (!inside) close(dialog);
  });

  document.addEventListener(
    'close',
    function (event) {
      if (event.target.tagName === 'DIALOG') {
        document.documentElement.style.overflow = '';
      }
    },
    true
  );

  /* ---------------------------------------------------------------------
     Swipe to dismiss a sheet
     ------------------------------------------------------------------ */

  /*
   * The gesture that most distinguishes an app sheet from a web modal. Kept to
   * one pointer, vertical only, and only when the sheet's own scroller is
   * already at the top — otherwise a customer scrolling a long list would drag
   * the sheet away instead.
   */
  document.querySelectorAll('.dlx-sheet').forEach(function (sheet) {
    var startY = 0;
    var offset = 0;
    var dragging = false;

    var body = sheet.querySelector('.dlx-sheet__body');

    sheet.addEventListener(
      'pointerdown',
      function (event) {
        if (event.pointerType === 'mouse') return;
        if (body && body.scrollTop > 0 && body.contains(event.target)) return;

        dragging = true;
        startY = event.clientY;
        offset = 0;
        sheet.style.transition = 'none';
      },
      { passive: true }
    );

    sheet.addEventListener(
      'pointermove',
      function (event) {
        if (!dragging) return;
        offset = Math.max(0, event.clientY - startY);
        sheet.style.translate = '0 ' + offset + 'px';
      },
      { passive: true }
    );

    function release() {
      if (!dragging) return;
      dragging = false;
      sheet.style.transition = '';
      sheet.style.translate = '';

      /* A third of the sheet's height, or a decisive flick, dismisses it. */
      if (offset > sheet.offsetHeight / 3) close(sheet);
    }

    sheet.addEventListener('pointerup', release, { passive: true });
    sheet.addEventListener('pointercancel', release, { passive: true });
  });

  /* ---------------------------------------------------------------------
     Cart count
     ------------------------------------------------------------------ */

  /*
   * WooCommerce announces every cart change on this event, whether the change
   * came from a mini-cart, a quantity field or its own AJAX add-to-cart. The
   * count in the tab bar follows it, so the badge is never stale and V10 needs
   * no cart transport of its own.
   */
  if (window.jQuery) {
    window.jQuery(document.body).on(
      'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded updated_cart_totals',
      function () {
        var source = document.querySelector('.dlx-cart-count-source');
        var badge = document.querySelector('.dlx-tab__count');
        if (!badge) return;

        var count = source ? parseInt(source.textContent, 10) : NaN;
        if (isNaN(count)) return;

        badge.textContent = count > 0 ? String(count) : '';
        badge.setAttribute('data-count', String(count));
      }
    );
  }
})();
