(function(){
  'use strict';

  function ready(fn){
    if(document.readyState!=='loading')fn();
    else document.addEventListener('DOMContentLoaded',fn,{once:true});
  }

  ready(function(){
    if(!document.body.classList.contains('dip-account-modern'))return;
    document.body.classList.add('dipma-is-ready');

    var avatar=document.querySelector('[data-dipma-avatar]');
    if(avatar){
      avatar.addEventListener('error',function(){avatar.setAttribute('data-failed','1');},{once:true});
    }

    var reduce=!!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var nav=document.querySelector('.woocommerce-MyAccount-navigation ul');
    var active=nav?nav.querySelector('.is-active'):null;
    if(nav&&active){
      requestAnimationFrame(function(){
        var left=active.offsetLeft-Math.max(12,(nav.clientWidth-active.offsetWidth)/2);
        try{nav.scrollTo({left:Math.max(0,left),behavior:reduce?'auto':'smooth'});}catch(e){nav.scrollLeft=Math.max(0,left);}
      });
    }

    var balance=document.querySelector('[data-dipma-balance]');
    var toggle=document.querySelector('[data-dipma-balance-toggle]');
    if(balance&&toggle){
      var original=balance.innerHTML;
      var hidden=false;
      try{hidden=sessionStorage.getItem('dipma_balance_hidden')==='1';}catch(e){}

      function renderBalance(state){
        hidden=!!state;
        balance.classList.toggle('is-hidden',hidden);
        balance.innerHTML=hidden?'••••':original;
        toggle.classList.toggle('is-hidden',hidden);
        toggle.setAttribute('aria-pressed',hidden?'true':'false');
        toggle.setAttribute('aria-label',hidden?'Afficher le solde':'Masquer le solde');
        try{sessionStorage.setItem('dipma_balance_hidden',hidden?'1':'0');}catch(e){}
      }

      renderBalance(hidden);
      toggle.addEventListener('click',function(){renderBalance(!hidden);});
    }

    document.querySelectorAll('.dipma-security-meter [data-score]').forEach(function(bar){
      var score=Math.max(0,Math.min(100,parseInt(bar.getAttribute('data-score'),10)||0));
      requestAnimationFrame(function(){bar.style.width=score+'%';});
    });

    document.querySelectorAll('[data-dipma-count]').forEach(function(el){
      if(el.dataset.dipmaCounted)return;
      el.dataset.dipmaCounted='1';
      var target=parseInt(el.getAttribute('data-dipma-count'),10)||0;
      if(reduce||target<=0){el.textContent=target.toLocaleString();return;}
      var start=null,duration=480;
      function step(ts){
        if(start===null)start=ts;
        var p=Math.min((ts-start)/duration,1);
        var eased=1-Math.pow(1-p,3);
        el.textContent=Math.floor(target*eased).toLocaleString();
        if(p<1)requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });

    var search=document.getElementById('dipma-order-search');
    if(search){
      var toolbar=search.closest('.dipma-orders-toolbar');
      var clear=toolbar?toolbar.querySelector('[data-dipma-search-clear]'):null;
      var empty=toolbar?toolbar.querySelector('[data-dipma-search-empty]'):null;

      function normalize(value){
        value=(value||'').toString().toLowerCase().trim();
        try{return value.normalize('NFD').replace(/[\u0300-\u036f]/g,'');}catch(e){return value;}
      }
      function rows(){
        return Array.prototype.slice.call(document.querySelectorAll('.woocommerce-orders-table tbody tr,table.woocommerce-orders-table tbody tr'));
      }
      function filter(){
        var q=normalize(search.value),list=rows(),visible=0;
        if(toolbar)toolbar.classList.toggle('has-value',!!q);
        list.forEach(function(row){
          var ok=!q||normalize(row.textContent).indexOf(q)!==-1;
          row.hidden=!ok;
          if(ok)visible++;
        });
        if(empty)empty.hidden=!(q&&list.length&&visible===0);
      }

      search.addEventListener('input',filter,{passive:true});
      if(clear)clear.addEventListener('click',function(){search.value='';filter();search.focus();});
      filter();
    }
  });
})();
