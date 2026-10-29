(function(){
  'use strict';

  // Everything is server-rendered (counts, score bar, masked balance): this
  // script only adds the interactions. Nothing here is needed for first paint.
  function ready(fn){
    if(document.readyState!=='loading')fn();
    else document.addEventListener('DOMContentLoaded',fn,{once:true});
  }

  ready(function(){
    if(!document.body||!document.body.classList.contains('dip-account-modern'))return;

    // Avatar: fall back to the initials when the picture cannot load (also when it failed before this ran).
    var avatar=document.querySelector('[data-dipma-avatar]');
    if(avatar){
      var fail=function(){avatar.setAttribute('data-failed','1');};
      if(avatar.complete&&avatar.naturalWidth===0)fail();
      else avatar.addEventListener('error',fail,{once:true});
    }

    // « Masquer le solde »: the preference is a first-party cookie (1/0 only),
    // so the server renders the masked state directly on the next visit.
    var balance=document.querySelector('[data-dipma-balance]');
    var toggle=document.querySelector('[data-dipma-balance-toggle]');
    if(balance&&toggle){
      var amount=balance.querySelector('[data-dipma-balance-amount]');
      var mask=balance.querySelector('[data-dipma-balance-mask]');
      var render=function(hidden){
        balance.classList.toggle('is-hidden',hidden);
        toggle.classList.toggle('is-hidden',hidden);
        if(amount)amount.hidden=hidden;
        if(mask)mask.hidden=!hidden;
        toggle.setAttribute('aria-pressed',hidden?'true':'false');
        toggle.setAttribute('aria-label',hidden?(toggle.getAttribute('data-label-show')||'Afficher le solde'):(toggle.getAttribute('data-label-hide')||'Masquer le solde'));
      };
      render(balance.classList.contains('is-hidden'));
      toggle.addEventListener('click',function(){
        var hidden=!balance.classList.contains('is-hidden');
        render(hidden);
        try{
          document.cookie='dipma_balance_hidden='+(hidden?'1':'0')+'; path=/; max-age=31536000; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
        }catch(e){}
      });
    }

    // Orders: filter the orders shown on this page.
    var search=document.getElementById('dipma-order-search');
    if(search){
      var toolbar=search.closest('.dipma-orders-toolbar');
      var clear=toolbar?toolbar.querySelector('[data-dipma-search-clear]'):null;
      var empty=toolbar?toolbar.querySelector('[data-dipma-search-empty]'):null;
      var emptyText=empty?empty.querySelector('[data-dipma-search-empty-text]'):null;
      var emptyPaged=empty?empty.querySelector('[data-dipma-search-paged]'):null;
      var paged=!!document.querySelector('.woocommerce-pagination,.woocommerce-Pagination');
      var rows=Array.prototype.slice.call(document.querySelectorAll('table.woocommerce-orders-table tbody tr'));
      var normalize=function(value){
        value=(value||'').toString().toLowerCase().trim();
        try{return value.normalize('NFD').replace(/[̀-ͯ]/g,'');}catch(e){return value;}
      };
      var texts=rows.map(function(row){return normalize(row.textContent);});
      var filter=function(){
        var q=normalize(search.value),visible=0;
        if(toolbar)toolbar.classList.toggle('has-value',!!q);
        rows.forEach(function(row,i){
          var ok=!q||texts[i].indexOf(q)!==-1;
          row.hidden=!ok;
          if(ok)visible++;
        });
        if(empty){
          empty.hidden=!(q&&rows.length&&visible===0);
          if(emptyText)emptyText.hidden=paged;
          if(emptyPaged)emptyPaged.hidden=!paged;
        }
      };
      search.addEventListener('input',filter,{passive:true});
      if(clear)clear.addEventListener('click',function(){search.value='';filter();search.focus();});
      if(search.value)filter();
    }
  });
})();
