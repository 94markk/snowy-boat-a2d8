(function () {
  "use strict";
  if (typeof FTRAdmin === "undefined") return;

  // ---- Generic copy-to-clipboard for any [data-copy="<elementId>"] button.
  // Used by the Webhook tab (callback URL) and the Sync tab (cron command).
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-copy]");
    if (!btn) return;
    var target = document.getElementById(btn.getAttribute("data-copy"));
    if (!target) return;
    var value = target.value || target.textContent || "";
    var originalLabel = btn.textContent;
    var done = function () {
      btn.textContent = "Copied!";
      setTimeout(function () { btn.textContent = originalLabel; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done, function () { fallback(); });
    } else {
      fallback();
    }
    function fallback() {
      try {
        target.removeAttribute("readonly");
        target.select();
        document.execCommand("copy");
        target.setAttribute("readonly", "readonly");
        done();
      } catch (_) {}
    }
  });

  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (html != null) e.innerHTML = html;
    return e;
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function openModal(title) {
    var overlay = el("div", { class: "ftr-modal-overlay" });
    var modal = el("div", { class: "ftr-modal" });
    var head = el("div", { class: "ftr-modal-head" });
    head.appendChild(el("h2", null, esc(title)));
    var x = el("button", { type: "button", class: "ftr-modal-x", "aria-label": "Close" }, "&times;");
    head.appendChild(x);
    var body = el("div", { class: "ftr-modal-body" });
    modal.appendChild(head);
    modal.appendChild(body);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    function close() { if (overlay.parentNode) overlay.parentNode.removeChild(overlay); }
    x.addEventListener("click", close);
    overlay.addEventListener("click", function (e) { if (e.target === overlay) close(); });
    return {
      body: body,
      close: close,
      setClosable: function (v) { x.style.display = v ? "" : "none"; }
    };
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.append("action", action);
    body.append("nonce", FTRAdmin.nonce);
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (Array.isArray(v)) { v.forEach(function (item) { body.append(k + "[]", item); }); }
      else { body.append(k, v); }
    });
    return fetch(FTRAdmin.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (r) { return r.json(); });
  }

  /* ---------- Check balance popup ---------- */
  var balBtn = document.getElementById("ftr-check-balance");
  if (balBtn) {
    balBtn.addEventListener("click", function () {
      var m = openModal("Wallet balance");
      m.body.innerHTML = '<p class="ftr-spin">Checking…</p>';
      post("ftr_check_balance", {}).then(function (res) {
        if (res && res.success) {
          var d = res.data;
          m.body.innerHTML =
            '<div class="ftr-balance">' +
              '<div class="ftr-balance-amt">' + esc(d.symbol || "") + esc(d.balance) + '</div>' +
              '<div class="ftr-balance-cur">' + esc(d.currency || "") + '</div>' +
              (d.sandbox ? '<p class="ftr-tag">Sandbox mode is ON</p>' : '') +
            '</div>';
        } else {
          m.body.innerHTML = '<p class="ftr-err">' + esc((res && res.data && res.data.message) || "Could not fetch balance.") + '</p>';
        }
      }).catch(function () { m.body.innerHTML = '<p class="ftr-err">Network error.</p>'; });
    });
  }

  /* ---------- WooCommerce self-test ---------- */
  function row(k, v, good) {
    return '<tr><th>' + esc(k) + '</th><td class="' + (good ? "ftr-ok" : "ftr-bad") + '">' + esc(v) + '</td></tr>';
  }

  var stBtn = document.getElementById("ftr-selftest");
  if (stBtn) {
    stBtn.addEventListener("click", function () {
      var m = openModal("Import self-test");
      m.body.innerHTML = '<p class="ftr-spin">Creating a test product…</p>';
      post("ftr_woo_selftest", {}).then(function (res) {
        if (!res || !res.success) {
          m.body.innerHTML = '<p class="ftr-err">' + esc((res && res.data && res.data.message) || "Self-test failed to run.") + '</p>';
          return;
        }
        var d = res.data;
        var rows = [];
        rows.push(row("WooCommerce active", d.woo_active ? "Yes" : "No", !!d.woo_active));
        if (d.wc_version) rows.push(row("WooCommerce version", d.wc_version, true));
        if (typeof d.product_id !== "undefined") rows.push(row("Test product ID", d.product_id || "—", !!d.product_id));
        if (typeof d.variations !== "undefined") rows.push(row("Variations created", d.variations, d.variations > 0));
        if (typeof d.exists !== "undefined") rows.push(row("Exists in WooCommerce", d.exists ? "Yes" : "No", !!d.exists));
        if (d.error) rows.push(row("Error", d.error, false));
        if (d.probe && d.probe.ran) {
          rows.push(row("Live API — sample product", d.probe.first_product || "—", !!d.probe.products_ok));
          rows.push(row("Live API — packages returned", d.probe.package_count, d.probe.package_count > 0));
          if (d.probe.services_message) rows.push(row("Live API — packages message", d.probe.services_message, !!d.probe.services_ok));
        } else if (d.probe && d.probe.catalog_empty) {
          rows.push(row("Live API — catalog", "empty (no products returned)", false));
        } else if (d.probe && d.probe.not_configured) {
          rows.push(row("Live API", "API not configured", false));
        }
        var html = '<p class="' + (d.ok ? "ftr-ok-msg" : "ftr-err") + '"><strong>' + esc(d.message || "") + '</strong></p>';
        html += '<table class="ftr-report">' + rows.join("") + '</table>';
        var links = "";
        if (d.edit_link) links += '<a class="button" target="_blank" rel="noopener" href="' + d.edit_link + '">Open the test product</a> ';
        if (FTRAdmin.productsUrl) links += '<a class="button" target="_blank" rel="noopener" href="' + FTRAdmin.productsUrl + '">View all products</a>';
        if (links) html += '<p class="ftr-modal-actions" style="text-align:left">' + links + '</p>';
        m.body.innerHTML = html;
      }).catch(function () { m.body.innerHTML = '<p class="ftr-err">Network error running self-test.</p>'; });
    });
  }

  /* ---------- Stepped import with progress ---------- */
  var importBtn = document.getElementById("ftr-import-btn");
  if (importBtn) {
    importBtn.addEventListener("click", function () {
      var form = document.getElementById("ftr-import-form");
      if (!form) return;
      var codes = Array.prototype.slice.call(form.querySelectorAll(".ftr-prod:checked")).map(function (c) { return c.value; });
      var ws = form.querySelector('[name="with_services"]');
      var withServices = ws ? ws.checked : true;
      if (!codes.length) { window.alert("Select at least one product to import."); return; }

      var m = openModal("Importing products");
      m.setClosable(false);
      m.body.innerHTML =
        '<div class="ftr-progress"><div class="ftr-progress-bar" id="ftr-pbar"></div></div>' +
        '<p class="ftr-progress-label" id="ftr-plabel">Preparing…</p>' +
        '<ul class="ftr-log" id="ftr-log"></ul>';
      var bar = document.getElementById("ftr-pbar");
      var label = document.getElementById("ftr-plabel");
      var log = document.getElementById("ftr-log");
      var totals = { games: 0, variations: 0, errors: 0 };

      function addLog(html, cls) {
        var li = el("li", cls ? { class: cls } : null, html);
        log.appendChild(li);
        log.scrollTop = log.scrollHeight;
      }

      function finish(failedStart, woo) {
        m.setClosable(true);
        if (failedStart) {
          label.innerHTML = "<strong>Import could not start.</strong>";
        } else {
          bar.style.width = "100%";
          label.innerHTML = "<strong>Done.</strong> " + totals.games + " game(s)" +
            (woo ? ", " + totals.variations + " variation(s)" : "") +
            (totals.errors ? ", " + totals.errors + " issue(s)" : "");
        }
        var actions = el("p", { class: "ftr-modal-actions" });
        var reload = el("button", { type: "button", class: "button button-primary" }, "Close &amp; refresh");
        reload.addEventListener("click", function () { window.location = FTRAdmin.importUrl; });
        actions.appendChild(reload);
        m.body.appendChild(actions);
      }

      post("ftr_import_prepare", { products: codes, with_services: withServices ? 1 : 0 }).then(function (res) {
        if (!res || !res.success) {
          label.textContent = "";
          addLog(esc((res && res.data && res.data.message) || "Could not start import."), "ftr-log-err");
          finish(true);
          return;
        }
        var list = res.data.codes || [];
        var total = res.data.total || list.length;
        var woo = res.data.woo;
        var done = 0;

        function step(i) {
          if (i >= list.length) { finish(false, woo); return; }
          var code = list[i];
          label.textContent = "Importing " + (i + 1) + " of " + total + "…";
          post("ftr_import_step", { code: code }).then(function (r) {
            done++;
            bar.style.width = Math.round((done / total) * 100) + "%";
            if (r && r.success) {
              var d = r.data;
              totals.games++;
              totals.variations += (d.variations || 0);
              if (d.error) {
                totals.errors++;
                addLog("⚠ <strong>" + esc(d.name) + "</strong> — " + esc(d.error), "ftr-log-warn");
              } else if (woo) {
                addLog("✓ <strong>" + esc(d.name) + "</strong> — " + (d.services || 0) + " package(s) → " + (d.variations || 0) + " variation(s)", "ftr-log-ok");
              } else {
                addLog("✓ <strong>" + esc(d.name) + "</strong> — " + (d.services || 0) + " package(s) saved", "ftr-log-ok");
              }
            } else {
              totals.errors++;
              addLog("✕ " + esc((r && r.data && r.data.message) || ("Failed: " + code)), "ftr-log-err");
            }
            step(i + 1);
          }).catch(function () {
            done++; totals.errors++;
            bar.style.width = Math.round((done / total) * 100) + "%";
            addLog("✕ Network error on " + esc(code), "ftr-log-err");
            step(i + 1);
          });
        }
        step(0);
      }).catch(function () {
        addLog("Network error starting import.", "ftr-log-err");
        finish(true);
      });
    });
  }


  /* ---------- Full supplier synchronization / ID reconciliation ---------- */
  var syncBtn = document.getElementById("ftr-sync-btn");
  if (syncBtn) {
    syncBtn.addEventListener("click", function () {
      var m = openModal("Synchronizing supplier catalog");
      m.setClosable(false);
      m.body.innerHTML =
        '<div class="ftr-progress"><div class="ftr-progress-bar" id="ftr-sync-pbar"></div></div>' +
        '<p class="ftr-progress-label" id="ftr-sync-label">Comparing supplier IDs and product mappings…</p>' +
        '<ul class="ftr-log" id="ftr-sync-log"></ul>';
      var bar = document.getElementById("ftr-sync-pbar");
      var label = document.getElementById("ftr-sync-label");
      var log = document.getElementById("ftr-sync-log");
      var totals = { products: 0, productRemaps: 0, serviceRemaps: 0, disabled: 0, errors: 0 };

      function addLog(html, cls) {
        var li = el("li", cls ? { class: cls } : null, html);
        log.appendChild(li);
        log.scrollTop = log.scrollHeight;
      }
      function finish() {
        bar.style.width = "100%";
        label.innerHTML = "<strong>Synchronization complete.</strong> " + totals.products + " product(s), " +
          totals.productRemaps + " product ID mapping(s) repaired, " + totals.serviceRemaps + " package mapping(s) repaired, " +
          totals.disabled + " removed package(s) disabled" + (totals.errors ? ", " + totals.errors + " issue(s)" : "") + ".";
        m.setClosable(true);
        var actions = el("p", { class: "ftr-modal-actions" });
        var reload = el("button", { type: "button", class: "button button-primary" }, "Close &amp; refresh");
        reload.addEventListener("click", function () { window.location = FTRAdmin.importUrl; });
        actions.appendChild(reload);
        m.body.appendChild(actions);
      }

      post("ftr_sync_prepare", {}).then(function (res) {
        if (!res || !res.success) {
          label.textContent = "";
          addLog("✕ " + esc((res && res.data && res.data.message) || "Could not start synchronization."), "ftr-log-err");
          m.setClosable(true);
          return;
        }
        var total = parseInt(res.data.total || 0, 10);
        var missing = res.data.missing || [];
        if (missing.length) {
          missing.forEach(function (x) {
            totals.errors++;
            addLog("⚠ <strong>" + esc(x.name || x.code) + "</strong> — no unique match in the current supplier catalog; existing Woo product was not modified.", "ftr-log-warn");
          });
        }
        if (!total) { finish(); return; }
        var done = 0;
        function step(i) {
          if (i >= total) { finish(); return; }
          label.textContent = "Synchronizing " + (i + 1) + " of " + total + "…";
          post("ftr_sync_step", { index: i }).then(function (r) {
            done++;
            bar.style.width = Math.round((done / total) * 100) + "%";
            if (r && r.success) {
              var d = r.data || {};
              totals.products++;
              if (d.product_remapped) totals.productRemaps++;
              totals.serviceRemaps += parseInt(d.remapped_services || 0, 10);
              totals.disabled += parseInt(d.orphaned_variations || 0, 10);
              if (d.error) {
                totals.errors++;
                addLog("⚠ <strong>" + esc(d.name) + "</strong> — " + esc(d.error), "ftr-log-warn");
              } else {
                var details = (d.services || 0) + " package(s)";
                if (d.product_remapped) details += " • supplier product ID/code repaired";
                if (d.remapped_services) details += " • " + d.remapped_services + " package ID/code repaired";
                if (d.orphaned_variations) details += " • " + d.orphaned_variations + " removed package(s) disabled";
                addLog("✓ <strong>" + esc(d.name) + "</strong> — " + details, "ftr-log-ok");
              }
            } else {
              totals.errors++;
              addLog("✕ " + esc((r && r.data && r.data.message) || "Synchronization failed."), "ftr-log-err");
            }
            step(i + 1);
          }).catch(function () {
            done++; totals.errors++;
            bar.style.width = Math.round((done / total) * 100) + "%";
            addLog("✕ Network error while synchronizing item " + (i + 1), "ftr-log-err");
            step(i + 1);
          });
        }
        step(0);
      }).catch(function () {
        label.textContent = "";
        addLog("✕ Network error starting synchronization.", "ftr-log-err");
        m.setClosable(true);
      });
    });
  }
  /* ---------- Sync tab: run a synchronization now ---------- */
  var syncNow = document.getElementById("ftr-sync-now");
  if (syncNow) {
    syncNow.addEventListener("click", function () {
      var out = document.getElementById("ftr-sync-now-msg");
      syncNow.disabled = true;
      out.textContent = "Synchronizing with the supplier…";
      post("ftr_sync_now", {}).then(function (res) {
        syncNow.disabled = false;
        out.textContent = (res && res.data && res.data.message) || (res && res.success ? "Done." : "Synchronization failed.");
        out.className = "description " + (res && res.success ? "ftr-ok" : "ftr-bad");
        if (res && res.success) setTimeout(function () { window.location.reload(); }, 1500);
      }).catch(function () {
        syncNow.disabled = false;
        out.textContent = "Network error.";
        out.className = "description ftr-bad";
      });
    });
  }
})();
