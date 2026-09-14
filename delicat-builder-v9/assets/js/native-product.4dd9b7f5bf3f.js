(function(){'use strict';
var cleanups=[];
var raf=window.requestAnimationFrame||function(cb){return window.setTimeout(cb,16)};
function moneyText(html){var box=document.createElement('div');box.innerHTML=String(html||'');var node=box.querySelector('ins .woocommerce-Price-amount,ins .amount')||box.querySelector('.woocommerce-Price-amount:last-child,.amount:last-child');return(node?node.textContent:box.textContent||'').replace(/\s+/g,' ').trim()}
function isDisabled(btn){return !btn||btn.disabled||btn.classList.contains('disabled')||btn.getAttribute('aria-disabled')==='true'}
function boot(root){
  if(!root||root.dataset.dnpBound==='1')return;root.dataset.dnpBound='1';
  var form=root.querySelector('form.cart');if(!form)return;
  var variable=root.dataset.variable==='1';
  var stock=root.querySelector('[data-dnp-stock]');
  var price=root.querySelector('[data-dnp-price]');
  /* RC37: another plugin (player-ID verification, swatches, variation refresh) may re-render
     the form's buttons or inputs; cached references then point at detached nodes and the dock
     stays disabled until a reload. Every check re-queries the live elements. */
  var liveForm=function(){return (root.isConnected?root:document).querySelector('form.cart')||form};
  var liveVariation=function(){return liveForm().querySelector('input.variation_id,input[name="variation_id"]')};
  var liveAdd=function(){return liveForm().querySelector('.single_add_to_cart_button')};
  var liveBuy=function(){return liveForm().querySelector('.dnp-buy-now')};
  var variationInput=liveVariation();
  var realAdd=liveAdd();
  var realBuy=liveBuy();
  var dock=document.querySelector('[data-dnp-dock][data-product-id="'+root.dataset.productId+'"]');
  var dockPrice=dock&&dock.querySelector('[data-dnp-dock-price]');
  var dockAdd=dock&&dock.querySelector('[data-dnp-proxy="add"]');
  var dockBuy=dock&&dock.querySelector('[data-dnp-proxy="buy"]');
  var baseOK=root.dataset.baseStock!=='0'&&root.dataset.basePurchasable!=='0';
  var variationOK=variable?false:baseOK;
  var currentPrice=variable?'Choisissez une option':(root.dataset.basePrice||moneyText(price&&price.innerHTML));
  var syncQueued=false;

  function selected(){var vi=liveVariation()||variationInput;return !variable||!!(vi&&parseInt(vi.value,10)>0)}
  function setStock(ok,label){if(!stock)return;stock.classList.toggle('is-neutral',ok===null);stock.classList.toggle('is-in',ok===true);stock.classList.toggle('is-out',ok===false);var text=stock.querySelector('span');var next=label||(ok===null?'Sélectionnez une option':(ok?'Disponible':'Rupture de stock'));if(text&&text.textContent!==next)text.textContent=next}
  /* RC39: the dock mirrors the live real button (RC37 live references). A block another script
     keeps on that button — e.g. until the player ID is verified — is respected, not lifted. */
  function ready(){return selected()&&variationOK===true&&!isDisabled(liveAdd()||realAdd)}
  function syncDock(){
    syncQueued=false;if(!root.isConnected||!dock||!dock.isConnected)return;
    if(variable&&!variationOK&&selected()){var vi=liveVariation();var hint=vi&&vi.form&&vi.form.querySelector('.single_variation .stock');variationOK=!(hint&&/out-of-stock|rupture/i.test(hint.className+' '+hint.textContent));if(variationOK)setStock(true,'Disponible')}
    if(variable&&selected()&&(!currentPrice||currentPrice==='Choisissez une option')){var pr=liveForm().querySelector('.woocommerce-variation-price .price,.single_variation .price');var txt=pr?moneyText(pr.innerHTML):'';if(txt)currentPrice=txt}
    var nextPrice=currentPrice||'Choisissez une option';if(dockPrice&&dockPrice.textContent!==nextPrice)dockPrice.textContent=nextPrice;
    var ok=ready();if(dockAdd&&dockAdd.disabled===ok)dockAdd.disabled=!ok;if(dockBuy&&dockBuy.disabled===ok)dockBuy.disabled=!ok;
    dock.hidden=false;document.body.classList.add('dnp-dock-visible','dnp-floating-ready');
  }
  function scheduleSync(){if(syncQueued)return;syncQueued=true;raf(syncDock)}
  function resetVariable(){variationOK=false;currentPrice='Choisissez une option';setStock(null);scheduleSync()}

  if(dock){
    dock.addEventListener('click',function(e){var btn=e.target.closest('[data-dnp-proxy]');if(!btn||btn.disabled)return;var real=btn.dataset.dnpProxy==='buy'?(liveBuy()||realBuy):(liveAdd()||realAdd);if(real&&!isDisabled(real)){if(btn.dataset.dnpProxy==='buy'&&window.DelicatCheckoutSheetOpen){window.DelicatCheckoutSheetOpen(liveForm(),real)}else real.click()}else scheduleSync()},{passive:true});
  }

  if(window.jQuery){
    var $=window.jQuery;
    $(form).on('found_variation.dnp',function(e,v){
      variationOK=!!(v&&v.is_in_stock!==false&&v.is_purchasable!==false&&v.variation_is_active!==false);
      setStock(variationOK,variationOK?'Disponible':'Rupture de stock');
      var next=moneyText(v&&v.price_html);if(price&&v&&v.price_html)price.innerHTML=v.price_html;if(next)currentPrice=next;scheduleSync();
    });
    $(form).on('hide_variation.dnp reset_data.dnp',function(){if(variable)resetVariable();else{variationOK=baseOK;setStock(baseOK);scheduleSync()}});
  }

  form.addEventListener('dmc:total_updated',function(e){var detail=e.detail||{};if(detail.ready&&detail.formatted&&detail.formatted!=='—')currentPrice=String(detail.formatted);else if(variable&&!selected())currentPrice='Choisissez une option';scheduleSync()});

  var observer=null;
  if('MutationObserver'in window){observer=new MutationObserver(scheduleSync);observer.observe(form,{attributes:true,subtree:true,childList:true,attributeFilter:['disabled','class','aria-disabled','value']})}
  form.addEventListener('change',scheduleSync);form.addEventListener('input',scheduleSync);
  document.addEventListener('dmc:field_verified',scheduleSync);document.addEventListener('ddg:verified',scheduleSync);document.addEventListener('delicat:verified',scheduleSync);
  window.addEventListener('pageshow',scheduleSync);
  cleanups.push(function(){
    if(root.isConnected)return true;
    if(observer)observer.disconnect();
    form.removeEventListener('change',scheduleSync);form.removeEventListener('input',scheduleSync);
    ['dmc:field_verified','ddg:verified','delicat:verified'].forEach(function(name){document.removeEventListener(name,scheduleSync)});
    window.removeEventListener('pageshow',scheduleSync);
    if(window.jQuery)window.jQuery(form).off('.dnp');
    return false;
  });

  setStock(variable?null:baseOK);scheduleSync();
}
function progressive(root){
  if(!root)return;root.querySelectorAll('.ddsw-root:not(.ddsw-preset-delicat-abonnement-premium) .ddsw-grid').forEach(function(grid){
    if(grid.dataset.dnpProgressive==='1')return;var cards=Array.prototype.slice.call(grid.children).filter(function(el){return el.classList&&el.classList.contains('ddsw-card')});if(cards.length<=12)return;
    grid.dataset.dnpProgressive='1';cards.slice(12).forEach(function(card){if(!card.classList.contains('is-selected'))card.hidden=true});
    var btn=document.createElement('button');btn.type='button';btn.className='dnp-more-options';btn.textContent='Voir plus ('+(cards.length-12)+')';btn.addEventListener('click',function(){cards.forEach(function(card){card.hidden=false});btn.remove()},{once:true});grid.insertAdjacentElement('afterend',btn)
  })
}
function all(){cleanups=cleanups.filter(function(cleanup){return cleanup()});document.querySelectorAll('.delicat-native-product').forEach(function(root){boot(root);progressive(root)})}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',all,{once:true});else all();document.addEventListener('dsb:content-updated',all);
})();
