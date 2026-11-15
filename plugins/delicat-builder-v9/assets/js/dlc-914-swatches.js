/* Delicat Builder V9 - Direct Swatches runtime (pack cards on variable product pages).
   9.14.0 (S-10): one cached file instead of 11.8 KB printed inline in every variable product page. The settings
   (window.DelicatSwatchConfig) and the stub that marks a pack tapped before this runs stay inline
   (delicat-direct-swatches-js-before). LiteSpeed delays jQuery and wc-add-to-cart-variation until the first
   touch: this waits for its DOMContentLiteSpeedLoaded event and the window load (one 2 s fallback when
   WooCommerce's variation form never arrives) instead of polling every 50 ms for up to 60 s.
   The path holds /delicat-builder-v9/: LiteSpeed's delay, defer and combine leave it alone (Audit_Fixes). */
(function(run){'use strict';var w=window,d=document,t=0;if(w.__ddswWait){return;}w.__ddswWait=1;
function go(force){if(w.__ddswReady){return true;}var $=w.jQuery;if(!$||(!$.fn.wc_variation_form&&!force)){return false;}w.__ddswReady=1;if(w.__ddswEarly){d.removeEventListener('click',w.__ddswEarly,true);}run($);var c=w.__ddswPending;w.__ddswPending=null;if(c&&c.isConnected){w.setTimeout(function(){c.click();},0);}return true;}
function later(){if(go(false)||t||!w.jQuery){return;}t=w.setTimeout(function(){go(true);},2000);}
if(!go(false)){d.addEventListener('DOMContentLiteSpeedLoaded',later);w.addEventListener('load',later);if(d.readyState==='complete'){later();}}
})(function($){'use strict';
var DSC=window.DelicatSwatchConfig||{};
var AUTO_RECOMMENDED=DSC.auto===true;
var PREMIUM_MOTION=/^(none|smooth|lift)$/.test(String(DSC.premiumMotion||''))?DSC.premiumMotion:'smooth';
var EFFECT=String(DSC.effect||'').replace(/[^A-Za-z0-9_-]/g,'');
function parseAttrs(el){try{return JSON.parse(el.getAttribute('data-attributes')||'{}');}catch(e){return {};}}
function formVariationReady(form){var v=form.querySelector('input.variation_id,input[name="variation_id"]');return v?parseInt(v.value||'0',10)>0:false;}
function emitPlan(form,card){
  var detail={variationId:parseInt(card.getAttribute('data-variation-id')||'0',10)||0,name:card.getAttribute('data-variation-name')||'',displayPrice:parseFloat(card.getAttribute('data-display-price')||'0')||0,recommended:card.getAttribute('data-recommended')==='1',attributes:parseAttrs(card)};
  try{form.dispatchEvent(new CustomEvent('dsb:plan_selected',{bubbles:true,detail:detail}));}catch(e){}
}
function syncPremiumState($root){
  if(String($root.attr('data-preset')||'')!=='delicat_abonnement_premium')return;
  $root.find('.ddsw-card').each(function(){var selected=this.classList.contains('is-selected');var cta=this.querySelector('.ddsw-plan-cta');if(!cta)return;var text=cta.querySelector('.ddsw-plan-cta-text');if(text){text.textContent=selected?(cta.getAttribute('data-selected-label')||'Plan sélectionné'):(cta.getAttribute('data-default-label')||'Choisir ce plan');}var icon=cta.querySelector('i');if(icon){icon.textContent=selected?'✓':'↗';}});
}
function initPremium($root){
  if(String($root.attr('data-preset')||'')!=='delicat_abonnement_premium')return;
  $root.find('.ddsw-subscription-section').each(function(){
    var section=this;if(section.getAttribute('data-ddsw-premium-init')==='1')return;section.setAttribute('data-ddsw-premium-init','1');
    var grid=section.querySelector('.ddsw-grid'),cards=grid?Array.prototype.slice.call(grid.querySelectorAll('.ddsw-card')):[];
    var prev=section.querySelector('.ddsw-plan-prev'),next=section.querySelector('.ddsw-plan-next'),index=section.querySelector('.ddsw-plan-index');
    if(!grid||!cards.length)return;
    var raf=0;
    function currentIndex(){var left=grid.scrollLeft,best=0,dist=Infinity;cards.forEach(function(card,i){var d=Math.abs(card.offsetLeft-grid.offsetLeft-left);if(d<dist){dist=d;best=i;}});return best;}
    function update(){raf=0;var i=currentIndex();if(index)index.textContent=(i+1)+' / '+cards.length;if(prev)prev.disabled=i<=0;if(next)next.disabled=i>=cards.length-1;}
    function go(delta){var i=Math.max(0,Math.min(cards.length-1,currentIndex()+delta));cards[i].scrollIntoView({behavior:(PREMIUM_MOTION==='none'||window.matchMedia('(prefers-reduced-motion: reduce)').matches)?'auto':'smooth',block:'nearest',inline:'start'});window.setTimeout(update,PREMIUM_MOTION==='none'?0:260);}
    if(prev)prev.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();go(-1);});
    if(next)next.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();go(1);});
    grid.addEventListener('scroll',function(){if(!raf)raf=window.requestAnimationFrame(update);},{passive:true});
    window.addEventListener('resize',update,{passive:true});update();
  });
  syncPremiumState($root);
}
function selectCard($form,$root,card){
  var form=$form.get(0); if(!form||!card)return;
  var attrs=parseAttrs(card),$selects=$form.find('select[name^="attribute_"]');
  var token=String(Date.now())+Math.random(); $form.data('ddswToken',token);
  $selects.each(function(){this.value='';});
  Object.keys(attrs).forEach(function(attr){var value=String(attrs[attr]||'');var selector='select[name="attribute_'+attr+'"],select[data-attribute_name="attribute_'+attr+'"]';var el=$form.find(selector).get(0);if(el){el.value=value;}});
  $root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');
  var $card=$(card).addClass('is-selected is-loading').attr('aria-checked','true');
  syncPremiumState($root);
  emitPlan(form,card);
  var first=$selects.get(0);
  if(first){$(first).trigger('change');}else{$form.trigger('check_variations');}
  window.setTimeout(function(){if($form.data('ddswToken')!==token)return;if(!formVariationReady(form)){$form.trigger('check_variations');} $card.removeClass('is-loading');},260);
}
function initFilters($form,$root){
  var bars=$root.find('.ddsw-filterbar');
  if(!bars.length)return;
  function findSelect(attr){return $form.find('select[name="attribute_'+attr+'"],select[data-attribute_name="attribute_'+attr+'"]').get(0);}
  function applyFilters(){
    var active={};
    bars.each(function(){var a=this.getAttribute('data-filter-attr');var t=this.querySelector('.ddsw-filter-tab.is-active');if(a&&t){active[a]=t.getAttribute('data-filter-value')||'';}});
    var deselect=false;
    $root.find('.ddsw-card').each(function(){
      var f={};try{f=JSON.parse(this.getAttribute('data-filters')||'{}');}catch(e){}
      var hide=false;
      Object.keys(active).forEach(function(a){if(active[a]&&f[a]&&f[a]!==active[a]){hide=true;}});
      if(hide&&this.classList.contains('is-selected')){deselect=true;}
      this.classList.toggle('is-filtered-out',hide);
    });
    return deselect;
  }
  function swapAnim(){$root.find('.ddsw-grid').each(function(){this.classList.remove('ddsw-swap');void this.offsetWidth;this.classList.add('ddsw-swap');});}
  function activate(bar,btn,animate){
    bar.querySelectorAll('.ddsw-filter-tab').forEach(function(t){var on=t===btn;t.classList.toggle('is-active',on);t.setAttribute('aria-selected',on?'true':'false');});
    if(animate){swapAnim();}
    var deselect=applyFilters();
    var attr=bar.getAttribute('data-filter-attr'),val=btn.getAttribute('data-filter-value')||'';
    var el=findSelect(attr);
    if(deselect){
      /* The selected card no longer belongs to this tab: clear every other
         attribute so WooCommerce resets price/summary, keep the filter value. */
      $form.find('select[name^="attribute_"]').each(function(){if(this!==el){this.value='';}});
      $root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');
    }
    if(el&&el.value!==val){el.value=val;$(el).trigger('change');}
    else if(deselect){$form.trigger('check_variations');}
  }
  $root.data('ddswApplyFilters',applyFilters);
  bars.each(function(){
    var bar=this;
    var el=findSelect(bar.getAttribute('data-filter-attr'));
    var current=el?String(el.value||''):'';
    var btn=null;
    if(current){
      var tabs=bar.querySelectorAll('.ddsw-filter-tab');
      for(var i=0;i<tabs.length;i++){if(tabs[i].getAttribute('data-filter-value')===current){btn=tabs[i];break;}}
    }
    if(!btn){btn=bar.querySelector('.ddsw-filter-tab');}
    if(btn){activate(bar,btn,false);}
    $(bar).off('.ddswFilter').on('click.ddswFilter','.ddsw-filter-tab',function(e){e.preventDefault();if(this.classList.contains('is-active'))return;activate(bar,this,true);});
  });
  $form.off('.ddswFilterSync').on('reset_data.ddswFilterSync',function(){
    window.setTimeout(function(){
      bars.each(function(){
        var a=this.getAttribute('data-filter-attr');var t=this.querySelector('.ddsw-filter-tab.is-active');if(!t)return;
        var el=findSelect(a);var v=t.getAttribute('data-filter-value')||'';
        if(el&&!el.value&&v){el.value=v;$(el).trigger('change');}
      });
      applyFilters();
    },0);
  });
}
function closeOos(modal){if(!modal)return;modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('ddsw-modal-open');}
function openOos(form,card){var modal=form.querySelector('.ddsw-oos-modal');if(!modal)return;var product=modal.getAttribute('data-product')||'',variation=card.getAttribute('data-variation-name')||'',template=modal.getAttribute('data-wa-message')||'',phone=(modal.getAttribute('data-phone')||'').replace(/\D/g,'');var msg=template.replace(/\{product\}/g,product).replace(/\{variation\}/g,variation);var link=modal.querySelector('.ddsw-oos-whatsapp');if(link){link.href=phone?'https://wa.me/'+phone+'?text='+encodeURIComponent(msg):'https://wa.me/?text='+encodeURIComponent(msg);}modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('ddsw-modal-open');var close=modal.querySelector('.ddsw-oos-close');if(close)close.focus();}
function init(form){
  var $form=$(form),$root=$form.children('.ddsw-root').first();if(!$root.length)return;
  if(String($root.data('hide-native'))==='yes'){$form.addClass('ddsw-ready');}
  if($form.attr('data-ddsw-initialized')==='1')return;
  $form.attr('data-ddsw-initialized','1');$root.addClass('ddsw-effect-'+EFFECT);
  $root.off('.ddsw').on('click.ddsw','.ddsw-card',function(e){e.preventDefault();if(this.getAttribute('data-out-of-stock')==='1'){openOos(form,this);return;}if(this.disabled)return;selectCard($form,$root,this);})
    .on('keydown.ddsw','.ddsw-card',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click();}})
    .on('click.ddsw','.ddsw-reset',function(e){e.preventDefault();var native=$form.find('.reset_variations').get(0);if(native){native.click();}else{$form.find('select[name^="attribute_"]').each(function(){this.value='';}).first().trigger('change');}$root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');syncPremiumState($root);});
  $form.off('.ddswSync').on('found_variation.ddswSync',function(e,variation){var id=parseInt(variation&&variation.variation_id,10)||0;$root.find('.ddsw-card').each(function(){var active=parseInt(this.getAttribute('data-variation-id'),10)===id;this.classList.toggle('is-selected',active);this.setAttribute('aria-checked',active?'true':'false');});$root.find('.ddsw-card').removeClass('is-loading');syncPremiumState($root);})
    .on('reset_data.ddswSync hide_variation.ddswSync',function(){$root.find('.ddsw-card').removeClass('is-selected is-loading').attr('aria-checked','false');syncPremiumState($root);});
  initFilters($form,$root);
  initPremium($root);
  if(AUTO_RECOMMENDED&&!formVariationReady(form)){
    var recommended=$root.find('.ddsw-card[data-recommended="1"][data-out-of-stock="0"]').not('.is-filtered-out').get(0);
    if(recommended){window.setTimeout(function(){if(form.isConnected&&!formVariationReady(form))recommended.click();},30);}
  }
}
function boot(){document.querySelectorAll('form.variations_form').forEach(init);}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot,{once:true});}else{boot();}
document.addEventListener('dsb:content-updated',boot);
$(document.body).on('updated_wc_div.ddsw wc_fragments_loaded.ddsw',boot).on('click.ddswOos','[data-ddsw-close]',function(){closeOos(this.closest('.ddsw-oos-modal'));});
$(document).on('keydown.ddswOos',function(e){if(e.key==='Escape'){document.querySelectorAll('.ddsw-oos-modal.is-open').forEach(closeOos);}});
});
