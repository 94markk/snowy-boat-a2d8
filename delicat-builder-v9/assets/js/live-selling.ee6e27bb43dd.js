(()=>{'use strict';
const cfg=window.DelicatLiveSelling||{};if(!cfg.endpoint)return;
const mobile=()=>window.matchMedia?window.matchMedia('(max-width:899px)').matches:innerWidth<900;
if((mobile()&&cfg.showMobile===false)||(!mobile()&&cfg.showDesktop===false))return;
const KEY='dbv9_live_sale_seen_v1';let timer=0,busy=false,toast=null,hideTimer=0,stylePromise=null;
/* RC55: the last-seen sale id lives in a first-party cookie (30 days), not localStorage — the storefront's standing storage rule. */
const seen=()=>{try{return decodeURIComponent((document.cookie.match(new RegExp('(?:^|;\\s*)'+KEY+'=([^;]*)'))||[])[1]||'')}catch(_){return''}};
const remember=id=>{try{document.cookie=KEY+'='+encodeURIComponent(String(id||''))+'; path=/; SameSite=Lax; max-age=2592000'+(location.protocol==='https:'?'; Secure':'')}catch(_){}};
const sameOrigin=value=>{try{const u=new URL(String(value||''),location.href);return u.origin===location.origin?u.href:'#'}catch(_){return'#'}};
const relative=ts=>{const sec=Math.max(0,Math.floor(Date.now()/1000-(Number(ts)||0)));if(sec<60)return"À l'instant";const m=Math.floor(sec/60);if(m<60)return`Il y a ${m} min`;const h=Math.floor(m/60);if(h<24)return`Il y a ${h} h`;const d=Math.floor(h/24);if(d<2)return'Hier';return'Récemment'};
const message=product=>{const tpl=String(cfg.messageTemplate||'Un client vient d’acheter {product}');return tpl.includes('{product}')?tpl.replace('{product}',String(product||'')):`Un client vient d’acheter ${String(product||'')}`};
const notify=visible=>{if(!document.body)return;document.body.classList.toggle('dbv9-live-sale-visible',!!visible);try{document.dispatchEvent(new CustomEvent('delicat:live-sale',{detail:{visible:!!visible}}))}catch(_){}};
const ensureStyle=()=>{
  if(stylePromise)return stylePromise;
  stylePromise=new Promise(resolve=>{
    const href=String(cfg.styleUrl||'');if(!href)return resolve(false);
    const existing=document.querySelector('link[data-dbv9-live-selling-style]');
    if(existing&&existing.sheet)return resolve(true);
    const link=document.createElement('link');link.rel='stylesheet';link.href=href;link.dataset.dbv9LiveSellingStyle='1';
    let done=false;const finish=ok=>{if(done)return;done=true;clearTimeout(timeout);link.onload=null;link.onerror=null;if(!ok)link.remove();resolve(ok)};
    const timeout=setTimeout(()=>finish(false),4000);
    link.onload=()=>finish(true);link.onerror=()=>finish(false);document.head.appendChild(link);
  }).then(ok=>{if(!ok)stylePromise=null;return ok});
  return stylePromise;
};
function ensureToast(){if(toast&&toast.isConnected)return toast;toast=document.createElement('aside');toast.className='dbv9-live-sale';toast.classList.add(cfg.position==='bottom_right'?'dbv9-live-sale--bottom-right':'dbv9-live-sale--bottom-left');toast.hidden=true;toast.setAttribute('aria-live','polite');toast.innerHTML='<button class="dbv9-live-sale__close" type="button" aria-label="Fermer">×</button><a class="dbv9-live-sale__link" href="#"><span class="dbv9-live-sale__media"><img class="dbv9-live-sale__image" width="48" height="48" alt="" loading="lazy" decoding="async"></span><span class="dbv9-live-sale__copy"><small></small><b></b><em></em></span><span class="dbv9-live-sale__arrow" aria-hidden="true">→</span></a>';document.body.appendChild(toast);const close=toast.querySelector('.dbv9-live-sale__close');close.hidden=cfg.showClose===false;close.addEventListener('click',()=>hide(true));return toast}
function hide(user=false){if(hideTimer){clearTimeout(hideTimer);hideTimer=0}if(!toast||!toast.isConnected){notify(false);return}const t=toast;t.classList.remove('is-visible');setTimeout(()=>{if(!t.classList.contains('is-visible')){t.hidden=true;notify(false)}},180);if(user){t.dataset.dismissed='1';if(timer){clearTimeout(timer);clearInterval(timer);timer=0}}}
async function show(item){if(!item||!item.id||!item.title)return;if(!await ensureStyle()||document.hidden)return;const t=ensureToast();if(t.dataset.dismissed==='1')return;const a=t.querySelector('.dbv9-live-sale__link'),img=t.querySelector('.dbv9-live-sale__image'),small=t.querySelector('small'),b=t.querySelector('b'),em=t.querySelector('em');a.href=sameOrigin(item.url);small.textContent=String(cfg.label||'🔥 Vente en direct');b.textContent=message(item.title);em.textContent=relative(item.created);if(item.image){img.src=item.image;img.closest('.dbv9-live-sale__media').hidden=false}else{img.removeAttribute('src');img.closest('.dbv9-live-sale__media').hidden=true}t.hidden=false;requestAnimationFrame(()=>{t.classList.add('is-visible');notify(true)});if(hideTimer)clearTimeout(hideTimer);const duration=Math.min(15000,Math.max(3000,Number(cfg.displayDuration)||6200));hideTimer=setTimeout(()=>hide(false),duration);remember(item.id)}
async function poll(){if(busy||document.hidden||navigator.onLine===false||(toast&&toast.dataset.dismissed==='1'))return;busy=true;let to=0;try{const u=new URL(cfg.endpoint,location.href),cursor=seen();if(cursor)u.searchParams.set('after',cursor);const ctl=typeof AbortController==='function'?new AbortController():null;to=ctl?setTimeout(()=>ctl.abort(),5000):0;const r=await fetch(u.href,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store',signal:ctl?ctl.signal:undefined});if(!r.ok)return;const data=await r.json(),items=Array.isArray(data.items)?data.items:[];if(items.length)await show(items[0])}catch(_){}finally{if(to)clearTimeout(to);busy=false}}
let started=false;
function schedule(){const delay=Math.min(60000,Math.max(4000,Number(cfg.initialDelay)||9000)),interval=Math.min(180000,Math.max(30000,Number(cfg.pollInterval)||45000));timer=setTimeout(()=>{started=true;poll();timer=setInterval(poll,interval)},delay)}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',schedule,{once:true});else schedule();document.addEventListener('visibilitychange',()=>{if(!document.hidden&&started&&(!toast||toast.dataset.dismissed!=='1'))poll()},{passive:true});
})();
