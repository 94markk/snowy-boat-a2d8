/* Delicat Builder 9.4.0 - Confirmation de commande: the shareable receipt, drawn by hand on a canvas
 * (never a capture of the page, which carries the codes), and its share / PNG / link actions. Loaded by
 * order-confirmation.js on the first share intent. Painted once per receipt state; the PNG is encoded
 * as soon as the sheet opens, so « Partager » usually runs inside the tap itself. */
(function () {
	'use strict';
	var d = document, w = window, api = w.DlcOc;
	if (!api || w.DlcOcReceipt) { return; }
	var node = d.getElementById('dlc-oc-receipt'), sheet = d.getElementById('dlc-oc-sheet'), cv = sheet ? sheet.querySelector('canvas') : null, R = null;
	try { R = node ? JSON.parse(node.textContent) : null; } catch (e) { R = null; }
	if (!R || !cv || !cv.getContext) { return; }
	var T = api.T, fmt = api.fmt, toast = api.toast, blob = null, painted = '', logo = null, ready = null, encoding = false, waiters = [];
	var INK = '#111827', MUTED = '#6B7280', RULE = '#E5E7EB', S = 3;
	var root = d.getElementById('dlc-oc'), FONT = (root && w.getComputedStyle ? w.getComputedStyle(root).fontFamily : '') || 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
	var PILLS = { paid: ['#DCFCE7', '#15803D'], pending: ['#FEF3C7', '#B45309'], neutral: ['#F1F5F9', '#475467'] };
	function sig() { return [R.pill.label, R.pill.tone, R.total.label].join('|'); }
	/* A live phase change: new pill and total label, drawn again on the next open (or now if open). */
	function patch(s) {
		var end = s.phase === 'cancelled' || s.phase === 'refunded';
		R.status = R.pill.label = s.pill.label; R.pill.tone = end ? 'neutral' : s.paid && s.phase !== 'failed' ? 'paid' : 'pending'; R.total.label = s.paid ? T.totalPaid : T.total;
		if (sheet.open) { draw(); }
	}
	function rr(c, x, y, wd, ht, r) { c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + wd, y, x + wd, y + ht, r); c.arcTo(x + wd, y + ht, x, y + ht, r); c.arcTo(x, y + ht, x, y, r); c.arcTo(x, y, x + wd, y, r); c.closePath(); }
	function ell(c, t, max) { t = String(t || ''); if (c.measureText(t).width <= max) { return t; } while (t.length > 1 && c.measureText(t + '…').width > max) { t = t.slice(0, -1); } return t.replace(/\s+$/, '') + '…'; }
	function wrap(c, text, max, n) {
		var words = String(text || '').split(/\s+/), out = [], cur = '', i = 0;
		while (i < words.length && out.length < n) {
			var t = cur ? cur + ' ' + words[i] : words[i];
			if (!cur || c.measureText(t).width <= max) { cur = t; i++; } else { out.push(cur); cur = ''; }
		}
		if (cur) { out.push(cur); }
		if (out.length) { out[out.length - 1] = ell(c, out[out.length - 1] + (i < words.length ? '…' : ''), max); }
		return out;
	}
	/* One layout function: measure pass (draw false) gives the height, then the draw pass. */
	function layout(c, draw) {
		var X = 12, Wc = 340, P = 16, y = 12, inner = Wc - 2 * P;
		function font(sz, wt) { c.font = (wt || 400) + ' ' + sz + 'px ' + FONT; }
		function text(t, x, yy, sz, wt, col, align) { font(sz, wt); if (draw) { c.fillStyle = col || INK; c.textAlign = align || 'left'; c.fillText(t, x, yy); } }
		function rule(yy) { if (draw) { c.save(); c.strokeStyle = RULE; c.lineWidth = 1; c.setLineDash([6, 4]); c.beginPath(); c.moveTo(X + P, yy + 0.5); c.lineTo(X + Wc - P, yy + 0.5); c.stroke(); c.restore(); } }
		var top = y, H = c.__h || 600;
		if (draw) {
			c.fillStyle = '#F8FAFC'; c.fillRect(0, 0, 364, H);
			c.save(); rr(c, X, top, Wc, H - 24, 20); c.fillStyle = '#fff'; c.fill(); c.strokeStyle = RULE; c.stroke(); c.clip();
		}
		/* Band */
		font(13, 700); var chip = '#' + R.number, chipW = c.measureText(chip).width + 20; font(16, 800);
		var under = 76 + c.measureText(R.brand.name).width + 12 + chipW > Wc - P, band = under ? 112 : 88;
		if (draw) {
			var g = c.createLinearGradient(X, top, X + Wc, top + band); g.addColorStop(0, '#3B9EFF'); g.addColorStop(1, '#0D53D6');
			c.fillStyle = g; c.fillRect(X, top, Wc, band);
			c.fillStyle = '#fff'; rr(c, X + P, top + 20, 48, 48, 12); c.fill();
			if (logo) { c.save(); rr(c, X + P + 4, top + 24, 40, 40, 9); c.clip(); c.drawImage(logo, X + P + 4, top + 24, 40, 40); c.restore(); } else { text('D', X + P + 24, top + 53, 26, 800, '#0D53D6', 'center'); }
		}
		text(R.brand.name, X + 76, top + 40, 16, 800, '#fff');
		text(R.brand.host, X + 76, top + 59, 12, 500, 'rgba(255,255,255,.82)');
		var cx = under ? X + 76 : X + Wc - P - chipW, cy = under ? top + 70 : top + 31;
		if (draw) { c.fillStyle = '#fff'; rr(c, cx, cy, chipW, 26, 13); c.fill(); }
		text(chip, cx + chipW / 2, cy + 18, 13, 700, INK, 'center');
		y = top + band + P;
		/* Meta + pill */
		var tone = PILLS[R.pill.tone] || PILLS.pending; font(12, 700); var pw = Math.min(150, c.measureText(R.pill.label).width + 20);
		text(T.date, X + P, y + 11, 11, 500, MUTED); text(R.date, X + P, y + 29, 13, 600);
		if (draw) { c.fillStyle = tone[0]; rr(c, X + Wc - P - pw, y + 6, pw, 24, 12); c.fill(); }
		font(12, 700); text(ell(c, R.pill.label, pw - 16), X + Wc - P - pw / 2, y + 22, 12, 700, tone[1], 'center');
		text(T.payment, X + P, y + 51, 11, 500, MUTED); font(13, 600); text(ell(c, R.payment, inner), X + P, y + 69, 13, 600);
		y += 82; rule(y); y += 14;
		/* Lines */
		(R.lines || []).forEach(function (l) {
			font(14, 600); var tw = c.measureText(l.total).width, avail = inner - 30, below = tw > avail * 0.38, nameW = below ? avail : avail - tw - 12;
			var names = wrap(c, l.name, nameW, 2);
			if (draw) { c.fillStyle = '#F1F5F9'; rr(c, X + P, y, 26, 22, 6); c.fill(); }
			text(l.qty + '×', X + P + 13, y + 15, 12, 700, INK, 'center');
			names.forEach(function (nm, i) { text(nm, X + P + 30, y + 15 + i * 19, 14, 600); });
			var yy = y + 15 + names.length * 19;
			if (l.player) { font(12, 400); text(ell(c, fmt(T.id, l.player), avail), X + P + 30, yy, 12, 400, MUTED); yy += 18; }
			if (below) { text(l.total, X + Wc - P, yy, 14, 600, INK, 'right'); yy += 19; } else { text(l.total, X + Wc - P, y + 15, 14, 600, INK, 'right'); }
			y = yy + 6;
		});
		if (R.more > 0) { text(fmt(T.more, R.more), X + P, y + 8, 12, 400, MUTED); y += 22; }
		rule(y); y += 14;
		/* Total: the amount takes its own line instead of squeezing */
		font(24, 800); var aw = c.measureText(R.total.value).width; font(11, 600); var lw = c.measureText(R.total.label).width * 1.15;
		if (lw + aw + 12 > inner) { text(R.total.label, X + P, y + 12, 11, 600, MUTED); text(R.total.value, X + Wc - P, y + 42, 24, 800, INK, 'right'); y += 54; } else { text(R.total.label, X + P, y + 24, 11, 600, MUTED); text(R.total.value, X + Wc - P, y + 28, 24, 800, INK, 'right'); y += 40; }
		rule(y); y += 14;
		/* Footer: QR (view-order link; none for guests) + three lines. Modules snap to whole device
		 * pixels (4 px at 3x for version 4) so the code stays crisp after a JPEG recompression. */
		var q = R.qr && R.qr.rows && R.qr.rows.length ? R.qr : null, tx = q ? X + P + 76 : X + P, fw = q ? inner - 76 : inner, fy = y + 14, k;
		if (q && draw) {
			var n = q.n, px = Math.max(1, Math.floor(64 * S / (n + 8))), m = px / S, qy = Math.round(y), off = Math.floor((64 * S - (n + 8) * px) / 2) / S;
			c.fillStyle = '#fff'; c.fillRect(X + P, qy, 64, 64); c.fillStyle = '#0D1B4D';
			q.rows.forEach(function (row, r) {
				var bits = '', run = -1;
				for (k = 0; k < row.length; k++) { bits += ('000' + parseInt(row[k], 16).toString(2)).slice(-4); }
				for (k = 0; k <= n; k++) {
					var on = k < n && bits[k] === '1';
					if (on && run < 0) { run = k; } else if (!on && run >= 0) { c.fillRect(X + P + off + (4 + run) * m, qy + off + (4 + r) * m, (k - run) * m, m); run = -1; }
				}
			});
		}
		(R.footer || []).forEach(function (line, i) {
			font(i ? 11 : 13, i ? 400 : 600);
			wrap(c, line, fw, 2).forEach(function (part) { text(part, tx, fy, i ? 11 : 13, i ? 400 : 600, i ? MUTED : INK); fy += i ? 15 : 18; });
		});
		y = Math.max(y + (q ? 64 : 0), fy - 8) + P;
		if (draw) { c.restore(); }
		return y + 12;
	}
	/* Logo and fonts once, then paint; the canvas is kept until the receipt changes. */
	function prepare() {
		if (!ready) {
			var pic = new Promise(function (ok) {
				if (!R.brand || !R.brand.logo) { ok(); return; }
				var img = new Image(); img.crossOrigin = 'anonymous'; img.decoding = 'async';
				img.onload = function () { logo = img; ok(); }; img.onerror = function () { ok(); };
				img.src = R.brand.logo;
			});
			ready = Promise.all([pic, d.fonts && d.fonts.ready ? d.fonts.ready.then(null, function () {}) : null]);
		}
		return ready;
	}
	function paint() {
		var c = cv.getContext('2d');
		c.setTransform(1, 0, 0, 1, 0, 0);
		var h = Math.ceil(layout(c, false));
		cv.width = 364 * S; cv.height = h * S; cv.style.aspectRatio = '364 / ' + h;
		c = cv.getContext('2d'); c.setTransform(S, 0, 0, S, 0, 0); c.__h = h; c.textBaseline = 'alphabetic';
		layout(c, true);
		painted = sig();
		encode();
	}
	function draw() { prepare().then(function () { if (painted !== sig()) { paint(); } }); }
	function flush() { var q = waiters; waiters = []; q.forEach(function (f) { f(blob); }); }
	function encode() {
		blob = null;
		if (!cv.toBlob) { flush(); return; }
		var mark = painted;
		encoding = true;
		try {
			cv.toBlob(function (b) { encoding = false; if (mark === painted) { blob = b; } flush(); }, 'image/png');
		} catch (e) {
			encoding = false;
			if (logo) { logo = null; paint(); } else { flush(); } // A logo without CORS taints the canvas: draw without it.
		}
	}
	function getBlob(cb) {
		if (painted === sig() && (blob || !cv.toBlob)) { cb(blob); return; }
		waiters.push(cb);
		if (painted !== sig()) { draw(); } else if (!encoding) { encode(); }
	}
	function fileName() { return (R.file || 'recu-delicat') + '.png'; }
	function download(b) {
		var a = d.createElement('a'), url = b ? URL.createObjectURL(b) : cv.toDataURL('image/png');
		a.href = url; a.download = fileName(); sheet.appendChild(a); a.click(); sheet.removeChild(a);
		if (b) { setTimeout(function () { URL.revokeObjectURL(url); }, 10000); }
		toast(T.saved);
	}
	function share(b) {
		var t = R.share_text, data = null;
		try { var f = b && w.File ? new File([b], fileName(), { type: 'image/png' }) : null; if (f && navigator.canShare && navigator.canShare({ files: [f] })) { data = { files: [f], title: t, text: t }; } } catch (e) { data = null; }
		if (!data && navigator.share) { data = { title: t, text: t }; if (R.link) { data.url = R.link; } }
		if (data) { navigator.share(data).catch(function (e) { if (!e || e.name !== 'AbortError') { toast(T.shareFail); } }); return; }
		api.copy(t + (R.link ? ' ' + R.link : '')).then(function () { toast(T.linkDone); }, function () { toast(T.shareFail); });
	}
	function act(a) {
		if (a === 'link') { api.copy(R.link).then(function () { toast(T.linkDone); }, function () { toast(T.copyFail); }); return; }
		if (a === 'png') { getBlob(download); return; }
		if (a === 'share') { getBlob(share); }
	}
	w.DlcOcReceipt = { draw: draw, act: act, patch: patch };
	api.patch = patch;
	if (api.last) { patch(api.last); }
}());
