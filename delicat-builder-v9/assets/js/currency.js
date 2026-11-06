(()=>{'use strict';
const go=code=>{if(!/^[A-Z]{3}$/.test(code||''))return;const u=new URL(location.href);u.searchParams.set('dmc_currency',code);u.searchParams.delete('add-to-cart');location.assign(u.toString())};
const boot=()=>document.querySelectorAll('[data-dbv9-currency]').forEach(root=>{if(root.dataset.ready==='1')return;root.dataset.ready='1';root.querySelector('[data-dbv9-currency-select]')?.addEventListener('change',e=>go(String(e.target.value||'').toUpperCase()));root.addEventListener('click',e=>{const b=e.target.closest('[data-dbv9-currency-value]');if(b)go(String(b.dataset.dbv9CurrencyValue||'').toUpperCase())})});
document.readyState==='loading'?document.addEventListener('DOMContentLoaded',boot,{once:true}):boot();document.addEventListener('delicat:navigation-complete',boot);
})();
