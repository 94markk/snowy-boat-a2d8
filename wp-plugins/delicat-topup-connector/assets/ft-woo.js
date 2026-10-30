(function () {
  "use strict";
  if (typeof TopupFields === "undefined") return;

  function setStatus(box, state, text) {
    if (!box) return;
    box.className = "ftr-wc-checkid-msg" + (state ? " is-" + state : "");
    box.textContent = text || "";
  }

  function resetVerification(input) {
    var wrap = input && input.closest(".ftr-wc-fields");
    if (!wrap) return;
    wrap.classList.remove("is-verified");
    setStatus(wrap.querySelector(".ftr-wc-checkid-msg"), "", "");
  }

  document.addEventListener("input", function (e) {
    var input = e.target.closest(".ftr-wc-player-row .ftr-wc-input");
    if (!input) return;
    var clear = input.parentNode.querySelector(".ftr-wc-clear");
    if (clear) clear.hidden = !input.value;
    resetVerification(input);
  });

  document.addEventListener("click", function (e) {
    var clear = e.target.closest(".ftr-wc-clear");
    if (clear) {
      e.preventDefault();
      var input = clear.parentNode.querySelector(".ftr-wc-input");
      if (input) { input.value = ""; input.focus(); input.dispatchEvent(new Event("input", { bubbles: true })); }
      clear.hidden = true;
      return;
    }

    var btn = e.target.closest(".ftr-wc-checkid");
    if (!btn) return;
    e.preventDefault();

    var wrap = btn.closest(".ftr-wc-fields");
    var product = btn.getAttribute("data-product");
    var fields = {};
    wrap.querySelectorAll(".ftr-wc-input").forEach(function (i) {
      fields[i.getAttribute("data-name")] = (i.value || "").trim();
    });

    var msg = wrap.querySelector(".ftr-wc-checkid-msg");
    var label = btn.querySelector(".ftr-wc-check-label");
    btn.disabled = true;
    if (label) label.textContent = "Vérification…";
    wrap.classList.remove("is-verified");
    setStatus(msg, "loading", "Vérification du compte…");

    var body = new URLSearchParams();
    body.append("action", TopupFields.action || "topup_verify_player");
    body.append("nonce", TopupFields.nonce);
    body.append("product_code", product);
    Object.keys(fields).forEach(function (k) { body.append("fields[" + k + "]", fields[k]); });

    fetch(TopupFields.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        if (label) label.textContent = "Vérifier l’ID";
        if (res && res.success) {
          wrap.classList.add("is-verified");
          setStatus(msg, "success", (res.data.message || "ID vérifié avec succès"));
        } else {
          setStatus(msg, "error", ((res && res.data && res.data.message) || "Impossible de vérifier cet ID"));
        }
      })
      .catch(function () {
        btn.disabled = false;
        if (label) label.textContent = "Vérifier l’ID";
        setStatus(msg, "error", "Erreur réseau. Réessayez.");
      });
  });

  document.querySelectorAll(".ftr-wc-player-row .ftr-wc-input").forEach(function (input) {
    var clear = input.parentNode.querySelector(".ftr-wc-clear");
    if (clear) clear.hidden = !input.value;
  });
})();
