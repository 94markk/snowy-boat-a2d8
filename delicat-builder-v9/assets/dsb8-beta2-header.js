(function(){'use strict';
  function closestMenuTrigger(){
    return document.querySelector('.dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle');
  }
  document.addEventListener('click',function(e){
    var button=e.target.closest('[data-dsb8-menu-trigger]');
    if(!button)return;
    var legacy=closestMenuTrigger();
    if(legacy && legacy!==button){legacy.click();return;}
    document.dispatchEvent(new CustomEvent('dsb8:overlay:open',{detail:{id:'modern-menu',trigger:button}}));
  });

  function setCartCount(value){
    var n=parseInt(value,10);
    if(!Number.isFinite(n)||n<0)n=0;
    document.querySelectorAll('[data-dsb8-cart-count]').forEach(function(el){
      el.textContent=String(n);
      el.hidden=n===0;
      el.setAttribute('aria-label',n+' article'+(n===1?'':'s')+' dans le panier');
    });
  }
  function wooCartCookieCount(){
    var match=document.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=([^;]+)/);
    if(!match)return null;
    var n=parseInt(decodeURIComponent(match[1]||''),10);
    return Number.isFinite(n)&&n>=0?n:null;
  }
  function syncFromFragment(){
    /* The Woo cookie is visitor-specific and avoids stale shared-page-cache badges. */
    var cookieCount=wooCartCookieCount();
    if(cookieCount!==null){setCartCount(cookieCount);return true;}
    var source=document.querySelector('.dsb8-woo-cart-count-fragment');
    if(source){setCartCount(source.getAttribute('data-count')||source.textContent);return true;}
    var fallback=document.querySelector('.cart-contents-count,.mini-cart-count,.widget_shopping_cart .count');
    if(fallback){setCartCount(fallback.textContent);return true;}
    return false;
  }
  function requestWooRefresh(){
    if(window.jQuery){
      window.jQuery(document.body).trigger('wc_fragment_refresh');
    }
  }
  syncFromFragment();
  document.addEventListener('DOMContentLoaded',syncFromFragment,{once:true});
  window.addEventListener('pageshow',function(e){syncFromFragment();if(e.persisted)requestWooRefresh();});

  if(window.jQuery){
    var $=window.jQuery;
    $(document.body).on('added_to_cart removed_from_cart updated_wc_div updated_cart_totals wc_fragments_loaded wc_fragments_refreshed',function(){
      window.setTimeout(syncFromFragment,0);
    });
    $(document.body).on('added_to_cart',function(e,fragments){
      if(fragments && fragments['.dsb8-woo-cart-count-fragment']){
        var box=document.createElement('div');box.innerHTML=fragments['.dsb8-woo-cart-count-fragment'];
        var node=box.firstElementChild;if(node)setCartCount(node.getAttribute('data-count')||node.textContent);
      }
    });
  }

  if(window.DSB8 && window.DSB8.emit){window.DSB8.emit('component:ready',{id:'header-beta2'});}
})();

/* v8.7.2 — panier : suppression fiable, panneau qui suit son contenu,
 * defilement de fond verrouille tant que le panneau est ouvert.
 *
 * Ce qui n'allait pas :
 *  1. Le verrou "is-busy" etait pose sur la RACINE du panier et n'etait jamais
 *     retire en cas de succes. Des la premiere suppression, removeItem()
 *     ressortait immediatement : les autres articles devenaient impossibles a
 *     supprimer. Le CSS .is-busy .dsb8-cart-item{opacity:.58} laissait en plus
 *     le fond rouge "Supprimer" transparaitre sous TOUTES les lignes.
 *  2. La ligne balayee restait a translateX(-120%) : invisible mais toujours
 *     haute. D'ou le grand vide au-dessus des articles restants.
 *  3. Un wc_fragment_refresh complet remplacait le panneau : il se fermait, et
 *     les ecouteurs poses directement sur les boutons mouraient avec l'ancien
 *     noeud.
 *  4. Aucun verrou de defilement : sur mobile le panneau est position:fixed,
 *     donc la page continuait de defiler derriere lui.
 */
(function(){'use strict';
  var SWIPE=82;
  var LOCK='dsb8-cart-locked';
  var wasOpen=false;
  var refreshTimer=null;

  function roots(){return document.querySelectorAll('[data-dsb8-cart-root]');}
  function each(list,fn){Array.prototype.forEach.call(list||[],fn);}
  function isMobile(){
    return window.matchMedia ? window.matchMedia('(max-width:767px)').matches : window.innerWidth<768;
  }
  function anyOpen(){return !!document.querySelector('[data-dsb8-cart-root].is-open');}

  /* ---------- page libre : le panier ne doit jamais figer le document ---------- */
  function unlockScroll(){
    var html=document.documentElement;
    html.classList.remove(LOCK);
    document.body.style.top='';
    document.body.style.position='';
    document.body.style.left='';
    document.body.style.right='';
    document.body.style.width='';
    document.body.style.overflow='';
  }
  function syncLock(){ unlockScroll(); }

  /* ---------- ouverture / fermeture ---------- */
  function setOpen(root,open){
    if(!root)return;
    root.classList.toggle('is-open',!!open);
    var btn=root.querySelector('[data-dsb8-cart-trigger]');
    if(btn)btn.setAttribute('aria-expanded',open?'true':'false');
    wasOpen=!!open;
    if(open)lastPageY=window.pageYOffset||0;
    syncLock();
  }
  function closeAll(except){
    each(roots(),function(r){if(r!==except&&r.classList.contains('is-open'))r.classList.remove('is-open');});
    each(document.querySelectorAll('[data-dsb8-cart-trigger]'),function(b){
      var r=b.closest('[data-dsb8-cart-root]');
      if(r&&!r.classList.contains('is-open'))b.setAttribute('aria-expanded','false');
    });
    if(!anyOpen())wasOpen=false;
    syncLock();
  }

  /* ---------- etat d'une ligne ---------- */
  function resetRow(wrap){
    if(!wrap)return;
    delete wrap.dataset.busy;
    wrap.classList.remove('is-removing','swipe-right');
    wrap.style.setProperty('--dsb8-cart-dx','0px');
    wrap.style.opacity='';
    wrap.style.maxHeight='';
    wrap.style.marginTop='';
    wrap.style.marginBottom='';
  }
  function collapseRow(wrap,done){
    var height=wrap.offsetHeight;
    wrap.style.maxHeight=height+'px';
    wrap.style.overflow='hidden';
    wrap.classList.add('is-removed');
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){
        wrap.style.maxHeight='0px';
        wrap.style.marginTop='0px';
        wrap.style.marginBottom='0px';
        wrap.style.opacity='0';
      });
    });
    window.setTimeout(done,240);
  }

  function showError(root,message){
    var list=root.querySelector('.dsb8-cart-list');
    if(!list)return;
    var old=list.querySelector('.dsb8-cart-error');
    if(old)old.parentNode.removeChild(old);
    var box=document.createElement('div');
    box.className='dsb8-cart-error';
    box.textContent=message||'Impossible de supprimer cet article.';
    list.insertBefore(box,list.firstChild);
    window.setTimeout(function(){if(box.parentNode)box.parentNode.removeChild(box);},3200);
  }

  /* ---------- mise a jour locale, sans remplacer le panneau ---------- */
  function updateAfterRemove(root,data){
    var count=parseInt(data&&data.count,10);
    if(!isFinite(count)||count<0)count=0;

    each(document.querySelectorAll('[data-dsb8-cart-count]'),function(el){
      el.textContent=String(count);
      el.hidden=count===0;
      el.setAttribute('aria-label',count+' article'+(count===1?'':'s')+' dans le panier');
    });
    if(data&&data.nonce)root.dataset.nonce=data.nonce;

    var foot=root.querySelector('.dsb8-cart-panel__foot');
    if(count===0){
      var list=root.querySelector('.dsb8-cart-list');
      if(list)list.innerHTML='<div class="dsb8-cart-empty">Votre panier est vide.</div>';
      if(foot&&foot.parentNode)foot.parentNode.removeChild(foot);
      return;
    }
    if(foot&&data&&data.subtotal){
      var total=foot.querySelector('strong');
      if(total){
        total.innerHTML=data.subtotal;
        total.classList.add('is-updated');
        window.setTimeout(function(){total.classList.remove('is-updated');},560);
      }
    }
  }

  /* Ne jamais remplacer le panneau pendant une suppression. Un refresh complet
     de fragments peut detruire le DOM alors qu'une deuxieme ligne est encore
     en cours de suppression. On notifie WooCommerce sans reconstruire ce panier. */
  function quietRefresh(root,data){
    if(!window.jQuery)return;
    window.clearTimeout(refreshTimer);
    refreshTimer=window.setTimeout(function(){
      var $=window.jQuery;
      $(document.body).trigger('removed_from_cart',[{},'',null]);
      $(document.body).trigger('dsb8_cart_updated',[data||{},root||null]);
    },40);
  }

  function removeItem(root,wrap,retried){
    if(!root||!wrap)return;
    if(wrap.dataset.busy==='1')return;            /* verrou PAR LIGNE, jamais global */
    var key=wrap.getAttribute('data-cart-key');
    if(!key)return;

    wrap.dataset.busy='1';
    wrap.classList.add('is-removing');

    var payload=new URLSearchParams();
    payload.set('action','delicat_builder_v9_header_remove_cart_item');
    payload.set('nonce',root.dataset.nonce||'');
    payload.set('cart_item_key',key);

    fetch(root.dataset.ajax,{
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
      body:payload.toString()
    })
    .then(function(r){return r.json().catch(function(){return null;});})
    .then(function(json){
      if(json&&json.success){
        collapseRow(wrap,function(){
          if(wrap.parentNode)wrap.parentNode.removeChild(wrap);
          updateAfterRemove(root,json.data||{});
          quietRefresh(root,json.data||{});
        });
        return;
      }
      var code=json&&json.data&&json.data.code;
      if(code==='stale_nonce'&&!retried){
        resetRow(wrap);
        retryAfterRefresh(key);
        return;
      }
      resetRow(wrap);
      showError(root,json&&json.data&&json.data.message);
    })
    .catch(function(){
      resetRow(wrap);
      showError(root);
    });
  }

  /* Page servie par le cache : le nonce est perime. On recharge le panier
     (ce qui en genere un neuf) puis on retente une seule fois. */
  function retryAfterRefresh(key){
    if(!window.jQuery){window.location.reload();return;}
    var $=window.jQuery;
    var run=function(){
      $(document.body).off('wc_fragments_refreshed wc_fragments_loaded',run);
      window.setTimeout(function(){
        /* On cherche la ligne dans tout le document puis on remonte a sa
           racine : une page peut porter plusieurs paniers (entete + barre
           mobile), et le premier n'est pas forcement le bon. */
        var wrap=document.querySelector('.dsb8-cart-swipe[data-cart-key="'+key.replace(/"/g,'\\"')+'"]');
        if(!wrap)return;
        var root=wrap.closest('[data-dsb8-cart-root]');
        if(root)removeItem(root,wrap,true);
      },60);
    };
    $(document.body).on('wc_fragments_refreshed wc_fragments_loaded',run);
    $(document.body).trigger('wc_fragment_refresh');
  }

  /* ---------- balayage ---------- */
  function bindSwipe(root,wrap){
    if(wrap.dataset.bound==='1')return;
    wrap.dataset.bound='1';
    var sx=0,sy=0,dx=0,drag=false,axis='';
    function start(x,y){sx=x;sy=y;dx=0;drag=true;axis='';wrap.classList.add('is-dragging');}
    function move(x,y,e){
      if(!drag)return;
      var mx=x-sx,my=y-sy;
      if(!axis){
        if(Math.abs(mx)<7&&Math.abs(my)<7)return;
        axis=Math.abs(mx)>Math.abs(my)?'x':'y';
        if(axis==='y'){drag=false;wrap.classList.remove('is-dragging');return;}
      }
      dx=Math.max(-150,Math.min(150,mx));
      if(e&&e.cancelable)e.preventDefault();
      wrap.style.setProperty('--dsb8-cart-dx',dx+'px');wrap.classList.toggle('is-swiping',Math.abs(dx)>2);
      wrap.classList.toggle('swipe-right',dx>0);
    }
    function end(){
      if(!drag)return;
      drag=false;
      wrap.classList.remove('is-dragging');
      if(Math.abs(dx)>=SWIPE){
        wrap.style.setProperty('--dsb8-cart-dx',(dx<0?'-120%':'120%'));
        window.setTimeout(function(){removeItem(root,wrap);},120);
      }else{
        wrap.style.setProperty('--dsb8-cart-dx','0px');wrap.classList.remove('is-swiping');
        wrap.classList.remove('swipe-right');
      }
      dx=0;
    }
    if(window.PointerEvent){
      wrap.addEventListener('pointerdown',function(e){
        if(e.target.closest&&e.target.closest('button,a'))return;
        if(e.pointerType==='mouse'&&e.button!==0)return;
        start(e.clientX,e.clientY);
      });
      wrap.addEventListener('pointermove',function(e){move(e.clientX,e.clientY,e);},{passive:false});
      wrap.addEventListener('pointerup',end);
      wrap.addEventListener('pointercancel',end);
    }else{
      wrap.addEventListener('touchstart',function(e){
        if(e.target.closest&&e.target.closest('button,a'))return;
        var t=e.touches[0];start(t.clientX,t.clientY);
      },{passive:true});
      wrap.addEventListener('touchmove',function(e){var t=e.touches[0];move(t.clientX,t.clientY,e);},{passive:false});
      wrap.addEventListener('touchend',end);
    }
  }

  /* ---------- delegation : survit au remplacement des fragments Woo ---------- */
  document.addEventListener('click',function(e){
    var target=e.target;
    if(!target||!target.closest)return;

    var trigger=target.closest('[data-dsb8-cart-trigger]');
    if(trigger){
      e.preventDefault();e.stopPropagation();
      var root=trigger.closest('[data-dsb8-cart-root]');
      if(!root)return;
      var open=!root.classList.contains('is-open');
      closeAll(root);
      setOpen(root,open);
      return;
    }
    if(target.closest('.dsb8-cart-panel__close')){
      e.preventDefault();
      setOpen(target.closest('[data-dsb8-cart-root]'),false);
      return;
    }
    var button=target.closest('.dsb8-cart-item__remove');
    if(button){
      e.preventDefault();e.stopPropagation();
      removeItem(button.closest('[data-dsb8-cart-root]'),button.closest('.dsb8-cart-swipe'));
      return;
    }
    if(!target.closest('[data-dsb8-cart-root]'))closeAll();
  });

  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});
  window.addEventListener('resize',syncLock,{passive:true});
  window.addEventListener('pagehide',unlockScroll);

  /* Le panneau suit le header mais ne reste pas colle a l'ecran pendant que
     l'utilisateur parcourt la page. Un vrai defilement de page le ferme; le
     defilement interne de la liste reste autorise. */
  var lastPageY=window.pageYOffset||0;
  var pageScrollFrame=0;
  window.addEventListener('scroll',function(){
    if(!wasOpen||pageScrollFrame)return;
    pageScrollFrame=window.requestAnimationFrame(function(){
      pageScrollFrame=0;
      if(!wasOpen)return;
      var y=window.pageYOffset||0;
      if(Math.abs(y-lastPageY)>10)closeAll();
      lastPageY=y;
    });
  },{passive:true});


  /* v8.7.5: iOS Safari may keep a sticky header/popup visually pinned while
     scrolling without producing a useful window scroll delta. Detect the
     user's page gesture directly. Internal cart-list scrolling remains open. */
  var pageTouchY=null;
  document.addEventListener('touchstart',function(e){
    if(!wasOpen||!e.touches||!e.touches[0]){pageTouchY=null;return;}
    var t=e.target;
    if(t&&t.closest&&t.closest('.dsb8-cart-list')){pageTouchY=null;return;}
    pageTouchY=e.touches[0].clientY;
  },{passive:true,capture:true});
  document.addEventListener('touchmove',function(e){
    if(pageTouchY===null||!wasOpen||!e.touches||!e.touches[0])return;
    if(Math.abs(e.touches[0].clientY-pageTouchY)>8){closeAll();pageTouchY=null;}
  },{passive:true,capture:true});
  document.addEventListener('touchend',function(){pageTouchY=null;},{passive:true,capture:true});
  document.addEventListener('touchcancel',function(){pageTouchY=null;},{passive:true,capture:true});
  window.addEventListener('wheel',function(e){
    if(wasOpen&&Math.abs(e.deltaY)>2&&!e.target.closest('.dsb8-cart-list'))closeAll();
  },{passive:true,capture:true});
  if(window.visualViewport){
    var vvTop=window.visualViewport.pageTop||0;
    window.visualViewport.addEventListener('scroll',function(){
      var next=window.visualViewport.pageTop||0;
      if(wasOpen&&Math.abs(next-vvTop)>6)closeAll();
      vvTop=next;
    },{passive:true});
  }

  function init(root){
    each(root.querySelectorAll('.dsb8-cart-swipe'),function(w){bindSwipe(root,w);});
  }
  function initAll(){
    each(roots(),init);
    /* Apres un remplacement de fragment, le nouveau noeud n'a plus is-open. */
    if(wasOpen&&!anyOpen()){
      var root=document.querySelector('[data-dsb8-cart-root]');
      if(root)setOpen(root,true);
    }
    syncLock();
  }

  document.readyState==='loading'
    ? document.addEventListener('DOMContentLoaded',initAll)
    : initAll();

  if(window.jQuery){
    window.jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded added_to_cart removed_from_cart',function(){
      window.setTimeout(initAll,0);
    });
  }

  /* Woo replaces the cart fragment inside Header v8. Observe only the header
     instead of the entire document tree; Woo events above remain the primary path. */
  if(window.MutationObserver){
    var headerRoot=document.querySelector('[data-dsb8-header]');
    if(headerRoot){
      var pending=null;
      new MutationObserver(function(records){
        for(var i=0;i<records.length;i++){
          if(!records[i].addedNodes.length&&!records[i].removedNodes.length)continue;
          window.clearTimeout(pending);
          pending=window.setTimeout(initAll,60);
          return;
        }
      }).observe(headerRoot,{childList:true,subtree:true});
    }
  }
})();

/* RC13: coordinate header overlays and guarantee direct smooth menu opening. */
(function(){'use strict';
  function closeHeaderPanels(){
    document.querySelectorAll('[data-dsb8-cart-root].is-open').forEach(function(root){
      root.classList.remove('is-open');
      var trigger=root.querySelector('[data-dsb8-cart-trigger]');
      if(trigger)trigger.setAttribute('aria-expanded','false');
    });
    document.querySelectorAll('.dsb541-notifications.is-open').forEach(function(root){root.classList.remove('is-open');});
  }
  document.addEventListener('dsb8:overlay:open',function(e){
    if(!e.detail||e.detail.id!=='modern-menu')return;
    closeHeaderPanels();
    var legacy=document.querySelector('.dsb-menu-toggle,[data-dsb-menu-toggle],.dsb-modern-menu-toggle');
    if(legacy && !legacy.matches('[data-dsb8-menu-trigger]')){
      legacy.dispatchEvent(new MouseEvent('click',{bubbles:true,cancelable:true,view:window}));
    }
  });
  document.addEventListener('click',function(e){
    var menu=e.target.closest&&e.target.closest('[data-dsb8-menu-trigger]');
    if(menu)closeHeaderPanels();
  },true);
})();

/* RC51.2 audit: duplicate mobile-cart scroll/touch listeners removed.
 * The primary cart runtime above already handles scroll, touch, wheel and visualViewport. */


