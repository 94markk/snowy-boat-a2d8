(function(){'use strict';
  function wooCount(){
    var m=document.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=([^;]+)/);
    if(!m)return null;
    var n=parseInt(decodeURIComponent(m[1]||''),10);
    return Number.isFinite(n)&&n>=0?n:null;
  }
  function sync(){
    var n=wooCount();
    if(n===null)return;
    document.querySelectorAll('[data-delicat-mobile-dock] [data-delicat-cart-count]').forEach(function(el){
      el.textContent=String(n);el.hidden=n===0;
      el.setAttribute('aria-label',n+' article'+(n===1?'':'s')+' dans le panier');
    });
  }
  sync();
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',sync,{once:true});
  window.addEventListener('pageshow',sync,{passive:true});
  document.addEventListener('delicat:navigation-complete',sync);
  document.addEventListener('wc-blocks_added_to_cart',function(){window.setTimeout(sync,0);});
  document.addEventListener('wc-blocks_removed_from_cart',function(){window.setTimeout(sync,0);});
  if(window.jQuery){
    window.jQuery(document.body).on('added_to_cart removed_from_cart updated_wc_div updated_cart_totals wc_fragments_loaded wc_fragments_refreshed',function(){window.setTimeout(sync,0);});
  }
})();
