(function () {
  "use strict";
  if (typeof FTR === "undefined") return;

  var grid = document.getElementById("ftr-grid");
  var modal = document.getElementById("ftr-modal");
  if (!grid || !modal) return;

  var current = null; // current product

  function money(price) {
    var n = parseFloat(price || 0);
    if (FTR.rate && FTR.rate > 0) n = n * FTR.rate;
    if (FTR.markup) n = n * (1 + FTR.markup / 100);
    if (!isFinite(n)) n = 0;
    return (FTR.currency || "USD") + " " + n.toFixed(2);
  }

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  // ---- grid ----
  var cards = [];
  FTR.products.forEach(function (p) {
    var card = el("button", "ftr-card");
    card.type = "button";
    card.setAttribute("data-name", (p.name || "").toLowerCase() + " " + (p.product_type || "").toLowerCase());
    var img = p.image_url
      ? '<img src="' + escAttr(p.image_url) + '" alt="" class="ftr-card-img" loading="lazy" />'
      : '<div class="ftr-card-img ftr-card-ph"></div>';
    card.innerHTML =
      img +
      '<div class="ftr-card-name">' + escHtml(p.name) + "</div>" +
      '<div class="ftr-card-type">' + escHtml(p.product_type) + "</div>";
    card.addEventListener("click", function () { open(p); });
    grid.appendChild(card);
    cards.push(card);
  });

  // ---- search / count ----
  var countEl = document.getElementById("ftr-count");
  var noResults = document.getElementById("ftr-noresults");
  var searchInput = document.getElementById("ftr-search-input");

  function updateCount(visible) {
    if (countEl) countEl.textContent = visible + (visible === 1 ? " product" : " products");
    if (noResults) noResults.classList.toggle("ftr-hidden", visible !== 0);
  }
  updateCount(cards.length);

  if (searchInput) {
    searchInput.addEventListener("input", function () {
      var q = this.value.trim().toLowerCase();
      var visible = 0;
      cards.forEach(function (c) {
        var match = q === "" || c.getAttribute("data-name").indexOf(q) !== -1;
        c.classList.toggle("ftr-hidden", !match);
        if (match) visible++;
      });
      updateCount(visible);
    });
  }

  // ---- modal ----
  function open(p) {
    current = p;
    document.getElementById("ftr-m-name").textContent = p.name;
    document.getElementById("ftr-m-type").textContent = p.product_type;
    var mimg = document.getElementById("ftr-m-img");
    if (p.image_url) { mimg.src = p.image_url; mimg.hidden = false; } else { mimg.hidden = true; }

    // packages
    var sel = document.getElementById("ftr-service");
    sel.innerHTML = "";
    (p.services || []).forEach(function (s) {
      if (s.in_stock === false) return;
      var o = document.createElement("option");
      o.value = s.service_code;
      o.textContent = s.service_name + " — " + money(s.price);
      o.setAttribute("data-price", s.price);
      o.setAttribute("data-max", s.max_quantity || 1);
      sel.appendChild(o);
    });
    if (!sel.options.length) {
      var o = document.createElement("option");
      o.textContent = "No packages available";
      o.value = "";
      sel.appendChild(o);
    }
    sel.onchange = updatePrice;

    // dynamic fields
    var wrap = document.getElementById("ftr-fields");
    wrap.innerHTML = "";
    (p.fields || []).forEach(function (f) {
      wrap.appendChild(el("label", "ftr-label", escHtml(f.label || f.name) + (f.required ? ' <span class="ftr-req">*</span>' : "")));
      var input;
      if (f.type === "select" && f.options && f.options.length) {
        input = document.createElement("select");
        input.className = "ftr-input ftr-field";
        f.options.forEach(function (opt) {
          var o = document.createElement("option");
          o.value = opt.value;
          o.textContent = opt.label != null ? opt.label : opt.value;
          input.appendChild(o);
        });
      } else {
        input = document.createElement("input");
        input.className = "ftr-input ftr-field";
        input.type = "text";
        if (f.placeholder) input.placeholder = f.placeholder;
      }
      input.setAttribute("data-name", f.name);
      wrap.appendChild(input);
    });

    // check id
    var ciRow = document.getElementById("ftr-checkid-row");
    ciRow.hidden = !p.check_id;
    document.getElementById("ftr-checkid-msg").textContent = "";

    document.getElementById("ftr-qty").value = 1;
    msg("");
    showSuccess(null);
    updatePrice();
    modal.hidden = false;
    document.body.style.overflow = "hidden";
  }

  // Clamp the quantity to [1, max-for-selected-package].
  function selectedMax() {
    var sel = document.getElementById("ftr-service");
    var opt = sel.options[sel.selectedIndex];
    var max = opt ? parseInt(opt.getAttribute("data-max"), 10) : 0;
    return max > 0 ? max : 9999;
  }
  function clampQty() {
    var q = document.getElementById("ftr-qty");
    var v = parseInt(q.value, 10);
    if (!isFinite(v) || v < 1) v = 1;
    var max = selectedMax();
    if (v > max) v = max;
    q.value = v;
    q.max = max;
  }

  function close() {
    modal.hidden = true;
    document.body.style.overflow = "";
  }

  function updatePrice() {
    var sel = document.getElementById("ftr-service");
    var opt = sel.options[sel.selectedIndex];
    clampQty();
    document.getElementById("ftr-price").textContent = opt && opt.getAttribute("data-price") ? money(opt.getAttribute("data-price")) : "";
  }

  // Render (or clear) the order-success card with copyable order/reference ids.
  function showSuccess(data) {
    var box = document.getElementById("ftr-success");
    if (!box) return;
    if (!data) { box.hidden = true; box.innerHTML = ""; return; }
    function kv(label, value, copyable) {
      if (value == null || value === "") return "";
      var v = escHtml(String(value));
      var btn = copyable ? ' <button type="button" class="ftr-copy-btn" data-copy-text="' + escAttr(String(value)) + '">Copy</button>' : "";
      return '<div class="ftr-kv"><span class="ftr-kv-key">' + escHtml(label) + '</span>' +
             '<span class="ftr-kv-val">' + v + btn + '</span></div>';
    }
    box.innerHTML =
      '<div class="ftr-success">' +
        '<div class="ftr-success-head">Order placed' + (data.sandbox ? ' <span class="ftr-tag">Sandbox</span>' : '') + '</div>' +
        kv("Order ID", data.order_id, true) +
        kv("Reference", data.reference_id, true) +
        '<div class="ftr-kv"><span class="ftr-kv-key">Status</span><span class="ftr-kv-val"><span class="ftr-badge">' + escHtml(data.status || "Processing") + '</span></span></div>' +
        (data.amount ? kv("Amount", (data.currency ? data.currency + " " : "") + data.amount, false) : "") +
      '</div>';
    box.hidden = false;
  }

  function collectFields() {
    var out = {};
    document.querySelectorAll("#ftr-fields .ftr-field").forEach(function (i) {
      out[i.getAttribute("data-name")] = i.value.trim();
    });
    return out;
  }

  function post(action, data, cb) {
    var body = new URLSearchParams();
    body.append("action", action);
    body.append("nonce", FTR.nonce);
    Object.keys(data).forEach(function (k) {
      if (k === "fields") {
        Object.keys(data.fields).forEach(function (fk) { body.append("fields[" + fk + "]", data.fields[fk]); });
      } else {
        body.append(k, data[k]);
      }
    });
    fetch(FTR.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { cb({ success: false, data: { message: "Network error." } }); });
  }

  function msg(text, type) {
    var m = document.getElementById("ftr-msg");
    if (!text) { m.hidden = true; m.textContent = ""; return; }
    m.hidden = false;
    m.textContent = text;
    m.className = "ftr-msg " + (type === "error" ? "ftr-msg-err" : type === "ok" ? "ftr-msg-ok" : "");
  }

  // check id
  document.getElementById("ftr-checkid").addEventListener("click", function () {
    if (!current) return;
    var btn = this;
    btn.disabled = true;
    var out = document.getElementById("ftr-checkid-msg");
    out.textContent = "Checking…";
    out.className = "ftr-checkid-msg";
    post("ftr_check_id", { product_code: current.product_code, fields: collectFields() }, function (res) {
      btn.disabled = false;
      if (res && res.success) {
        out.textContent = "✓ " + (res.data.message || "Verified");
        out.className = "ftr-checkid-msg ftr-ok";
      } else {
        out.textContent = "✕ " + ((res && res.data && res.data.message) || "Could not verify");
        out.className = "ftr-checkid-msg ftr-err";
      }
    });
  });

  // order
  document.getElementById("ftr-order").addEventListener("click", function () {
    if (!current) return;
    var sel = document.getElementById("ftr-service");
    var service = sel.value;
    if (!service) { msg("Please choose a package.", "error"); return; }
    var btn = this;
    btn.disabled = true;
    msg("Placing order…");
    post("ftr_order", {
      product_code: current.product_code,
      service_code: service,
      quantity: document.getElementById("ftr-qty").value || 1,
      fields: collectFields(),
    }, function (res) {
      btn.disabled = false;
      if (res && res.success) {
        msg("");
        showSuccess(res.data || {});
      } else {
        showSuccess(null);
        msg((res && res.data && res.data.message) || "Order failed.", "error");
      }
    });
  });

  // Quantity stepper.
  function bumpQty(delta) {
    var q = document.getElementById("ftr-qty");
    q.value = (parseInt(q.value, 10) || 1) + delta;
    clampQty();
  }
  var qMinus = document.getElementById("ftr-qty-minus");
  var qPlus = document.getElementById("ftr-qty-plus");
  if (qMinus) qMinus.addEventListener("click", function () { bumpQty(-1); });
  if (qPlus) qPlus.addEventListener("click", function () { bumpQty(1); });
  var qInput = document.getElementById("ftr-qty");
  if (qInput) qInput.addEventListener("change", clampQty);

  // Copy buttons inside the success card.
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-copy-text]") : null;
    if (!b) return;
    var text = b.getAttribute("data-copy-text") || "";
    var done = function () { var t = b.textContent; b.textContent = "Copied"; setTimeout(function () { b.textContent = t; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
    } else {
      done();
    }
  });

  // close handlers
  modal.querySelectorAll("[data-ftr-close]").forEach(function (b) {
    b.addEventListener("click", close);
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !modal.hidden) close(); });

  function escHtml(s) { var d = document.createElement("div"); d.textContent = s == null ? "" : String(s); return d.innerHTML; }
  function escAttr(s) { return escHtml(s).replace(/"/g, "&quot;"); }
})();
