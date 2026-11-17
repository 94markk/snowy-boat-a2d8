<?php
/** Pre-3.3 page CSS, printed only by delicat_print_css() (kept for themes that may still call it). */
defined( 'ABSPATH' ) || exit;

function delicat_print_css_legacy() {
	echo <<<'CSS'
<style>
.dlc{--navy:#1b2a4a;--purple:#5e3f97;--purple-dark:#4a3179;--purple-deep:#33215a;--indigo:#5b4fc7;
--mauve:#e1cdd4;--mauve-2:#ecdde1;--mauve-3:#f4eaed;--cream:#f3f0e3;--ink:#2a2230;--muted:#7a7280;
--gold:#d9a93b;--silver:#9aa3b2;--bronze:#c08454;--ok:#2e9e6b;--shadow:0 18px 40px -22px rgba(51,33,90,.55);
font-family:'Poppins',sans-serif;color:var(--ink);max-width:520px;margin:0 auto;line-height:1.5}
.dlc *{box-sizing:border-box}
.dlc h1.title{font-family:'Playfair Display',serif;font-weight:800;text-align:center;font-size:clamp(2.1rem,9vw,2.8rem);color:var(--ink);margin:26px 0 22px;letter-spacing:.5px}
.dlc .card{background:linear-gradient(160deg,var(--mauve-2),var(--mauve));border-radius:18px;padding:22px 20px;box-shadow:var(--shadow)}
.dlc .brand-mark{display:flex;flex-direction:column;align-items:center;gap:6px;padding-bottom:14px;border-bottom:1.5px solid rgba(60,40,90,.25)}
.dlc .brand-mark .mono{font-family:'Playfair Display',serif;font-weight:800;font-size:2.2rem;color:var(--purple-deep);line-height:1}
.dlc .brand-mark img{display:block;max-width:220px;max-height:125px;width:auto;height:auto;object-fit:contain}.dlc .brand-mark .leaf{font-size:1.4rem}.dlc .brand-mark small{font-size:.55rem;letter-spacing:2px;color:var(--purple-deep);font-weight:600}
.dlc .prog-name{text-align:center;font-weight:700;margin:14px 0 16px;font-size:.98rem}
.dlc .stat{background:var(--cream);border-radius:12px;text-align:center;padding:16px 14px;margin-bottom:14px}
.dlc .stat .lab{color:#5b5560;font-size:.95rem}.dlc .stat .val{font-size:1.5rem;font-weight:700;margin-top:2px}.dlc .stat .val.gold{color:var(--purple-deep)}
.dlc .nb{border-top:1.5px solid rgba(60,40,90,.25);padding-top:14px;text-align:center;color:#4d4654;font-size:.86rem}
.dlc h2.section{font-weight:700;text-align:center;letter-spacing:1px;margin:34px 0 18px;font-size:1.25rem}
.dlc .tier{background:linear-gradient(160deg,var(--mauve-2),var(--mauve));border-radius:16px;padding:18px;margin-bottom:18px;box-shadow:var(--shadow);opacity:0;transform:translateY(14px);animation:dlcrise .5s forwards}
@keyframes dlcrise{to{opacity:1;transform:none}}
.dlc .tier-top{display:flex;justify-content:space-between;gap:10px}.dlc .tier-name{font-weight:700;font-size:1.02rem;line-height:1.3}
.dlc .badge{color:var(--indigo);font-weight:700;text-align:right;font-size:.95rem;line-height:1.2;flex-shrink:0}
.dlc .track{height:9px;border-radius:6px;background:var(--cream);margin:16px 0 9px;overflow:hidden;box-shadow:inset 0 1px 3px rgba(0,0,0,.08)}
.dlc .fill{height:100%;width:0;border-radius:6px;background:linear-gradient(90deg,var(--indigo),var(--purple));transition:width .6s cubic-bezier(.4,0,.2,1)}
.dlc .meta{display:flex;justify-content:space-between;color:#4d4654;font-size:.9rem;font-weight:500}
.dlc .claim{width:100%;margin-top:15px;border:0;cursor:pointer;color:#fff;font-family:inherit;font-weight:600;background:linear-gradient(180deg,var(--purple),var(--purple-dark));padding:13px 12px;border-radius:9px;font-size:.82rem;letter-spacing:.4px;text-transform:uppercase;transition:.15s;box-shadow:0 8px 18px -10px rgba(74,49,121,.9)}
.dlc .claim:hover{filter:brightness(1.06)}.dlc .claim:disabled{background:#c3b6cf;cursor:not-allowed;box-shadow:none;filter:none}
.dlc .claim.ready{animation:dlcpulse 1.6s infinite}@keyframes dlcpulse{50%{box-shadow:0 8px 26px -6px rgba(94,63,151,.95)}}
.dlc .login{text-align:center;background:var(--cream);border-radius:12px;padding:18px;margin-top:8px}
.dlc .login a{display:inline-block;margin-top:8px;background:var(--purple);color:#fff;text-decoration:none;padding:10px 22px;border-radius:9px;font-weight:600}
/* leaderboard */
.dlc .hero{margin-top:18px;border-radius:22px;padding:30px 24px 28px;color:#fff;text-align:center;background:linear-gradient(155deg,#6a48aa,var(--purple) 45%,var(--purple-deep));box-shadow:var(--shadow);position:relative;overflow:hidden}
.dlc .hero::after{content:"";position:absolute;inset:0;background:radial-gradient(400px 200px at 80% -10%,rgba(255,255,255,.18),transparent 60%)}
.dlc .kicker{font-size:.74rem;letter-spacing:3px;font-weight:700;opacity:.92;margin-bottom:8px}
.dlc .hero h1{font-family:'Playfair Display',serif;font-weight:900;font-size:clamp(2.1rem,9.5vw,3rem);line-height:1.02}
.dlc .games{font-weight:600;font-size:.92rem;margin-top:10px;opacity:.95}
.dlc .reset{font-size:.74rem;letter-spacing:2px;font-weight:600;margin-top:18px;opacity:.85}.dlc .reset b{color:#ffe7a6;font-weight:800}
.dlc .reward{margin-top:18px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;text-align:left}
.dlc .reward .cup{font-size:2.3rem}.dlc .reward .r-lab{font-size:.68rem;letter-spacing:2px;font-weight:700;opacity:.85}
.dlc .reward .r-big{font-size:1.7rem;font-weight:800;margin:2px 0}.dlc .reward .r-sub{font-size:.82rem;opacity:.9}
.dlc .you{margin-top:16px;border:1.5px solid #e6d8de;background:#fff;border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 10px 26px -20px rgba(51,33,90,.5)}
.dlc .you .badge-rank{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:var(--mauve-3);color:var(--purple);font-weight:800;font-size:1.1rem;flex-shrink:0}
.dlc .you .y-lab{font-size:.78rem;color:var(--muted)}.dlc .you .y-name{font-weight:700;font-size:1.05rem}
.dlc .lbl{font-weight:800;letter-spacing:2px;margin:26px 4px 14px;font-size:.92rem;color:var(--muted)}
.dlc .row{background:#fff;border:1px solid #efe7ea;border-radius:16px;padding:14px 16px;margin-bottom:11px;display:flex;align-items:center;gap:14px;box-shadow:0 10px 24px -22px rgba(51,33,90,.6);opacity:0;transform:translateY(12px);animation:dlcrise .45s forwards}
.dlc .rank{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:1rem;flex-shrink:0;background:var(--mauve-3)}
.dlc .rank.medal{font-size:1.5rem;border:2px solid}.dlc .rank.g{border-color:var(--gold);background:#fbf3dc}.dlc .rank.s{border-color:var(--silver);background:#eef0f4}.dlc .rank.b{border-color:var(--bronze);background:#f6e7da}
.dlc .name{font-weight:700;font-size:1.02rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis}.dlc .row.top .name{color:var(--purple-deep)}
.dlc .lock{display:flex;align-items:center;gap:6px;color:#a59bb0;font-weight:600;font-size:.9rem;flex-shrink:0}
.dlc .foot{margin-top:22px;text-align:center;color:var(--muted);font-size:.82rem;line-height:1.6}.dlc .foot a{color:var(--purple);font-weight:700;text-decoration:none}
.dlc-wa{position:fixed;right:18px;bottom:20px;width:56px;height:56px;border-radius:50%;background:#25d366;display:flex;align-items:center;justify-content:center;box-shadow:0 12px 26px -8px rgba(37,211,102,.7);z-index:60;text-decoration:none}
.dlc-wa svg{width:30px;height:30px;fill:#fff}
.dlc-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(40px);background:var(--purple-deep,#33215a);color:#fff;padding:13px 20px;border-radius:12px;font-weight:600;font-size:.9rem;opacity:0;transition:.3s;z-index:80;max-width:90vw;text-align:center;font-family:'Poppins',sans-serif}
.dlc-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

/* =======================================================================
   REWARDS PAGE — v2.7 Premium Rewards. Fully scoped to #dlc-rewards.
   Lightweight: no backdrop-filter, no permanent particles, GPU-safe motion.
   ======================================================================= */
/* =======================================================================
   MEILLEUR CLIENT — "Diamond Vault" dark theme + podium. Scoped entirely
   to #dlc-board: the rewards page and its Premium Journey additions are
   untouched. No color-mix / backdrop-filter (weak-Android safe).
   ======================================================================= */
#dlc-board{font-family:'Inter',system-ui,sans-serif;color:#EDF1F8;max-width:520px;margin:0 auto;
  background:radial-gradient(560px 340px at 50% -70px,rgba(76,141,255,.14),transparent 60%),
             radial-gradient(520px 320px at 112% 8%,rgba(155,140,255,.12),transparent 55%),
             linear-gradient(180deg,#0A0F1A,#070A11);
  border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:6px 16px 26px;
  box-shadow:0 30px 70px -30px rgba(0,0,0,.72);overflow:hidden}
#dlc-board .hero{margin-top:14px;border-radius:22px;padding:28px 22px 24px;text-align:center;color:#fff;
  background:linear-gradient(160deg,#241a52,#191436 55%,#0d0a1f);border:1px solid rgba(155,140,255,.22);
  box-shadow:0 26px 60px -30px rgba(0,0,0,.85);position:relative;overflow:hidden}
#dlc-board .hero::after{content:"";position:absolute;inset:0;pointer-events:none;
  background:radial-gradient(360px 200px at 82% -12%,rgba(124,141,255,.30),transparent 60%),
            radial-gradient(300px 180px at 6% 6%,rgba(44,229,224,.14),transparent 60%)}
#dlc-board .hero::before{content:"";position:absolute;inset:-40% -10%;pointer-events:none;z-index:0;
  background:linear-gradient(115deg,transparent 42%,rgba(255,255,255,.07) 49%,rgba(155,140,255,.12) 53%,transparent 62%);
  transform:translateX(-130%);animation:dlcSheenB 7s ease-in-out infinite}
@keyframes dlcSheenB{0%,68%{transform:translateX(-130%)}86%,100%{transform:translateX(130%)}}
#dlc-board .kicker{position:relative;font-family:'Inter',sans-serif;font-size:.72rem;letter-spacing:.24em;
  font-weight:700;text-transform:uppercase;color:#C9B8FF;opacity:1;margin-bottom:10px}
#dlc-board .hero h1{position:relative;font-family:'Sora',sans-serif;font-weight:800;
  font-size:clamp(2rem,9vw,2.8rem);line-height:1.02;letter-spacing:-.01em;color:#F4F7FF;
  text-shadow:0 2px 34px rgba(124,141,255,.35)}
#dlc-board .games{position:relative;font-weight:600;font-size:.92rem;margin-top:12px;color:#D7DEEC;opacity:1}
#dlc-board .reset{position:relative;display:inline-flex;align-items:center;gap:8px;margin-top:18px;
  padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);
  font-family:'Space Grotesk',sans-serif;font-size:.76rem;letter-spacing:.14em;font-weight:600;color:#9AA3C0;opacity:1}
#dlc-board .reset b{color:#7CE0FF;font-weight:800;font-family:'Space Grotesk',sans-serif;font-variant-numeric:tabular-nums}
#dlc-board .reward{position:relative;margin-top:18px;background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:16px 18px;display:flex;align-items:center;gap:14px;text-align:left}
#dlc-board .reward .cup{font-size:2.2rem;filter:drop-shadow(0 4px 12px rgba(246,197,96,.5))}
#dlc-board .reward .r-lab{font-family:'Inter',sans-serif;font-size:.66rem;letter-spacing:.16em;font-weight:700;
  color:#C9B8FF;text-transform:uppercase;opacity:1}
#dlc-board .reward .r-big{font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;margin:3px 0;color:#fff}
#dlc-board .reward .r-sub{font-size:.82rem;color:#C6CFE0;opacity:1}
#dlc-board .score-rule{position:relative;margin:11px auto 0;padding:7px 11px;border-radius:999px;width:max-content;max-width:100%;
  background:rgba(44,229,224,.08);border:1px solid rgba(44,229,224,.18);color:#BFD5E2;
  font-family:'Space Grotesk',sans-serif;font-size:.69rem;font-weight:650;letter-spacing:.02em}
#dlc-board .you{margin-top:16px;background:linear-gradient(180deg,#141B29,#10151F);border:1px solid rgba(255,255,255,.10);
  border-radius:18px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 20px 44px -30px rgba(0,0,0,.85)}
#dlc-board .you .badge-rank{width:50px;height:50px;border-radius:13px;display:grid;place-items:center;
  background:rgba(44,229,224,.12);border:1px solid rgba(44,229,224,.30);color:#35E0E0;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1.05rem}
#dlc-board .you .y-lab{font-size:.74rem;color:#8592AB;letter-spacing:.04em}
#dlc-board .you .y-name{font-weight:700;font-size:1.02rem;color:#EDF1F8}
#dlc-board .you .y-name a{color:#35E0E0;font-weight:700}
#dlc-board .you .y-stats{margin-top:5px;font-family:'Space Grotesk',sans-serif;font-size:.76rem;color:#AEB9CC}
#dlc-board .lbl{display:flex;align-items:center;gap:12px;font-family:'Sora',sans-serif;font-weight:700;
  letter-spacing:.08em;margin:26px 4px 14px;font-size:.86rem;color:#8592AB;text-transform:uppercase}
#dlc-board .lbl::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,rgba(255,255,255,.14),transparent)}
#dlc-board .row{background:linear-gradient(180deg,#141B29,#10151F);border:1px solid rgba(255,255,255,.07);
  border-radius:16px;padding:13px 15px;margin-bottom:11px;display:flex;align-items:center;gap:14px;
  box-shadow:0 16px 40px -32px rgba(0,0,0,.9)}
#dlc-board .rank{width:46px;height:46px;border-radius:12px;display:grid;place-items:center;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1rem;flex-shrink:0;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#C6CFE0}
#dlc-board .name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1rem;color:#EDF1F8;flex:1;
  min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#dlc-board .lock{display:flex;align-items:center;gap:6px;color:#6F7A92;font-weight:600;font-size:.86rem;flex-shrink:0}
#dlc-board .login{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;
  padding:18px;text-align:center;color:#C6CFE0}
#dlc-board .foot{margin-top:22px;text-align:center;color:#8592AB;font-size:.82rem;line-height:1.7}
#dlc-board .foot a{color:#35E0E0;font-weight:700;text-decoration:none}
#dlc-board .podium{display:flex;align-items:flex-end;gap:10px;margin:2px 0 16px}
#dlc-board .pod{--pc:#C7D2E4;flex:1;min-width:0;text-align:center;position:relative;border-radius:18px;
  background:linear-gradient(180deg,#161E2E,#10151F);border:1px solid rgba(255,255,255,.09);
  padding:14px 8px 12px;opacity:0;transform:translateY(14px);animation:dlcrise .5s forwards;overflow:hidden}
#dlc-board .pod::after{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--pc)}
#dlc-board .pod-1{--pc:#F6C560;flex:1.18;padding:18px 8px 22px;border-color:rgba(246,197,96,.45);
  box-shadow:0 18px 44px -24px rgba(246,197,96,.45)}
#dlc-board .pod-2{--pc:#C7D2E4}
#dlc-board .pod-3{--pc:#CE9A6A}
#dlc-board .pod-medal{font-size:1.8rem;line-height:1}
#dlc-board .pod-1 .pod-medal{font-size:2.1rem;animation:dlcCrown 3.2s ease-in-out infinite}
@keyframes dlcCrown{50%{transform:translateY(-4px)}}
#dlc-board .pod-ava{width:50px;height:50px;border-radius:50%;margin:9px auto;display:grid;place-items:center;
  font-family:'Space Grotesk',sans-serif;font-weight:800;font-size:1rem;color:#fff;
  background:rgba(255,255,255,.06);border:2px solid var(--pc);box-shadow:0 0 20px -6px var(--pc)}
#dlc-board .pod-1 .pod-ava{width:58px;height:58px;font-size:1.15rem}
#dlc-board .pod-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.78rem;color:#EDF1F8;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 2px}
#dlc-board .pod-rank{font-family:'Space Grotesk',sans-serif;font-size:.64rem;letter-spacing:.14em;color:#8592AB;margin-top:3px}
#dlc-board .pod-bar{height:7px;margin-top:10px;border-radius:6px;background:rgba(255,255,255,.07);overflow:hidden}
#dlc-board .pod-bar i{display:block;height:100%;width:0;border-radius:6px;background:var(--pc);
  box-shadow:0 0 10px var(--pc);transition:width 1s cubic-bezier(.22,1,.36,1)}
#dlc-board .pod-prize{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.72rem;color:var(--pc);margin-top:5px}
#dlc-board .pod-score{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.72rem;color:#AEB9CC;margin-top:5px}
#dlc-board .row-score{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.8rem;color:#AEB9CC;white-space:nowrap}
#dlc-board .heat{height:5px;margin-top:7px;border-radius:6px;background:rgba(255,255,255,.06);overflow:hidden;max-width:180px}
#dlc-board .heat i{display:block;height:100%;width:0;border-radius:6px;
  background:linear-gradient(90deg,#4C8DFF,#35E0E0);box-shadow:0 0 8px rgba(53,224,224,.6);
  transition:width 1s cubic-bezier(.22,1,.36,1)}
@media(prefers-reduced-motion:reduce){#dlc-board .hero::before{animation:none}#dlc-board .pod{animation:none;opacity:1;transform:none}#dlc-board .pod-1 .pod-medal{animation:none}#dlc-board .pod-bar i,#dlc-board .heat i{transition:none}}
</style>
CSS;
}
