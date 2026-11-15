/* Delicat Builder V9 - event promotions on the storefront (9.12.2): the slim banner « [Nom] : -X % avec le code
   CODE » + « Copier », and the one-tap offer in the checkout coupon box, drawn from the public JSON of the events
   running now (data-url; cached 5 min in the tab), never from the cached page.
   9.14.0 (S-10, J-M4): a cached plugin file instead of 4.4 KB inline on every page, printed only while an event
   is scheduled or running. 9.14.0 (S-7): the chat bubble's lift is read from #dsa7-root. */
(function(d,w){var S=d.currentScript||d.getElementById('delicat-event-promos-js'),U=(S&&S.getAttribute('data-url'))||'/wp-json/delicat-builder-v9/v1/promos',K='dlc-promos',T=300000;
function get(cb){try{var c=JSON.parse(sessionStorage.getItem(K)||'null');if(c&&Date.now()-c.t<T){cb(c.p);return}}catch(e){}
if(!w.fetch||(w.DelicatBackoff&&w.DelicatBackoff.on())){cb([]);return}fetch(U,{credentials:'same-origin'}).then(function(r){w.DelicatBackoff&&w.DelicatBackoff.trip(r.status);return r.ok?r.json():{promos:[]}}).then(function(j){var p=(j&&j.promos)||[];try{sessionStorage.setItem(K,JSON.stringify({t:Date.now(),p:p}))}catch(e){}cb(p)}).catch(function(){cb([])})}
function el(t,c,x){var e=d.createElement(t);if(c)e.className=c;if(x!=null)e.textContent=x;return e}
function copy(code,b){function done(){var o=b.textContent;b.textContent='Copié';setTimeout(function(){b.textContent=o},1400)}
if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(code).then(done,function(){fb()})}else fb();
function fb(){var i=el('input');i.value=code;i.setAttribute('readonly','');i.style.position='fixed';i.style.opacity='0';d.body.appendChild(i);i.select();try{d.execCommand('copy');done()}catch(e){}i.remove()}}
function banner(p){var b=p.filter(function(x){return x.banner})[0];if(!b||d.getElementById('dlc-promo-bar'))return;
var bc=d.body.className;if(/(^|\s)(dlc-buy-page|dpn-checkout|dpn-cart)(\s|$)/.test(bc))return;
try{if(sessionStorage.getItem('dlc-promo-x-'+b.code))return}catch(e){}
var bar=el('div','dlc-promo-bar');bar.id='dlc-promo-bar';bar.setAttribute('role','region');bar.setAttribute('aria-label','Promotion');
var t=el('p','dlc-promo-bar__text');t.appendChild(el('strong','',b.name));t.appendChild(d.createTextNode(' : '+(b.text||(b.label+' avec le code '))));if(!b.text){t.appendChild(el('b','dlc-promo-bar__code',b.code))}
var c=el('button','dlc-promo-bar__copy','Copier');c.type='button';c.addEventListener('click',function(){copy(b.code,c)});
var x=el('button','dlc-promo-bar__x');x.type='button';x.setAttribute('aria-label','Fermer');x.innerHTML='<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
x.addEventListener('click',function(){bar.remove();try{sessionStorage.setItem('dlc-promo-x-'+b.code,'1')}catch(e){}});
bar.appendChild(t);bar.appendChild(c);bar.appendChild(x);d.body.appendChild(bar);place(bar);setTimeout(function(){place(bar)},900);setTimeout(function(){place(bar)},2500);w.addEventListener('resize',function(){place(bar)},{passive:true})}
/* the chat bubble (another plugin) is never covered: when it sits in the bar's way, the bar rests just above it */
function place(bar){if(!bar.isConnected)return;var q=d.querySelector('#dsa7-root .dsa7-launcher, .dap-launcher')||d.getElementById('dsa7-root');if(!q)return;var r=q.getBoundingClientRect();if(!r.width||!r.height)return;var lr=d.getElementById('dsa7-root'),o=parseFloat((lr&&lr.style.getPropertyValue('--dlc-bubble-lift'))||d.documentElement.style.getPropertyValue('--dlc-bubble-lift'))||0,top=r.top+o,bot=r.bottom+o;bar.style.bottom='';var br=bar.getBoundingClientRect();if(r.left<br.right&&r.right>br.left&&top<br.bottom+4&&bot>br.top-4)bar.style.bottom=Math.round(w.innerHeight-top+8)+'px'}
function checkout(p){var f=d.querySelector('form.checkout_coupon input[name="coupon_code"]');if(!f||d.getElementById('dlc-promo-offer'))return;var o=p[0];if(!o)return;
var applied=d.querySelector('.woocommerce-checkout-review-order-table tr.cart-discount.coupon-'+o.code.toLowerCase());if(applied)return;
var r=el('div','dlc-promo-offer');r.id='dlc-promo-offer';r.appendChild(el('span','','Code '+o.code+' disponible ('+o.label+')'));
var a=el('button','dlc-promo-offer__apply','Appliquer');a.type='button';a.addEventListener('click',function(){var form=f.form;f.value=o.code;var tg=d.querySelector('.showcoupon');if(form&&form.style.display==='none'&&tg)tg.click();if(form){if(form.requestSubmit)form.requestSubmit();else{var s=form.querySelector('[type=submit]');s&&s.click()}}r.remove()});
r.appendChild(a);var box=d.querySelector('.dlc-co-coupon')||f.form;box&&box.parentNode&&box.parentNode.insertBefore(r,box)}
function run(){get(function(p){w.DelicatPromos=p;if(!p.length)return;banner(p);checkout(p);try{d.dispatchEvent(new CustomEvent('dlc:promos',{detail:p}))}catch(e){}})}
if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',run);else run();d.addEventListener('delicat:navigated',run);
})(document,window);
