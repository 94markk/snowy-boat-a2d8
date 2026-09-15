(function(){'use strict';
var studio=document.querySelector('[data-ddsw-studio]');if(!studio)return;
var nav=Array.prototype.slice.call(studio.querySelectorAll('[data-ddsw-tab-target]'));
var panels=Array.prototype.slice.call(studio.querySelectorAll('[data-ddsw-panel]'));
function activate(key){
  nav.forEach(function(btn){var on=btn.getAttribute('data-ddsw-tab-target')===key;btn.classList.toggle('is-active',on);btn.setAttribute('aria-selected',on?'true':'false');});
  panels.forEach(function(panel){panel.classList.toggle('is-active',panel.getAttribute('data-ddsw-panel')===key);});
  try{window.sessionStorage.setItem('ddsw_studio_tab',key);}catch(e){}
}
nav.forEach(function(btn){btn.addEventListener('click',function(){activate(btn.getAttribute('data-ddsw-tab-target'));});});
try{var remembered=window.sessionStorage.getItem('ddsw_studio_tab');if(remembered&&studio.querySelector('[data-ddsw-panel="'+remembered+'"]'))activate(remembered);}catch(e){}

var preview=studio.querySelector('[data-ddsw-preview]');
function field(key){return studio.querySelector('[name="ddsw['+key+']"]');}
function value(key,fallback){var el=field(key);return el?String(el.value||fallback):String(fallback||'');}
function checked(key){var el=field(key);return !!(el&&el.checked);}
function css(name,val){if(preview)preview.style.setProperty(name,val);}
function px(key,fallback){var n=parseInt(value(key,fallback),10);return (isFinite(n)?n:fallback)+'px';}
function refreshPreview(){
  if(!preview)return;
  css('--pv-accent',value('subscription_accent','#ff1744'));css('--pv-accent2',value('subscription_accent_2','#ff334f'));
  css('--pv-navy',value('subscription_navy','#07142e'));css('--pv-gold',value('subscription_gold','#f5b82e'));
  css('--pv-card',value('subscription_card_bg','#fff'));css('--pv-text',value('subscription_text','#0f172a'));
  css('--pv-muted',value('subscription_muted','#64748b'));css('--pv-border',value('subscription_border','#e4e7ef'));
  css('--pv-section',value('subscription_section_bg','#f7f8fc'));css('--pv-gap',px('subscription_card_gap',16));
  css('--pv-minh',px('subscription_card_min_height',286));css('--pv-heading',px('subscription_heading_size',28));
  css('--pv-title',px('subscription_title_size',24));css('--pv-price',px('subscription_price_size',26));
  css('--pv-plan-align',value('subscription_plan_alignment','center'));
  css('--pv-cta-height',px('subscription_cta_height',48));css('--pv-cta-radius',px('subscription_cta_radius',14));
  var h=preview.querySelector('.ddsw-preview-heading h2'),p=preview.querySelector('.ddsw-preview-heading p');
  if(h)h.textContent=value('subscription_heading','Choisissez votre plan');if(p)p.textContent=value('subscription_subtitle','Sélectionnez la durée qui vous convient.');
  preview.querySelectorAll('.ddsw-preview-plan').forEach(function(card){var cta=card.querySelector('.cta');if(cta)cta.textContent=card.classList.contains('selected')?value('subscription_selected_label','Plan sélectionné'):value('subscription_cta_label','Choisir ce plan');var ul=card.querySelector('ul');if(ul)ul.style.display=checked('subscription_show_features')?'grid':'none';if(cta)cta.style.display=checked('subscription_show_cta')?'flex':'none';});
  var trust=preview.querySelector('.ddsw-preview-trust');if(trust){trust.style.display=checked('subscription_show_trust')?'grid':'none';var spans=trust.querySelectorAll('span');if(spans[0])spans[0].textContent='🛡 '+value('subscription_trust_1','Paiement 100% sécurisé');if(spans[1])spans[1].textContent='⚡ '+value('subscription_trust_2','Livraison instantanée');if(spans[2])spans[2].textContent='◉ '+value('subscription_trust_3','Support 24/7');}
}
studio.addEventListener('input',function(e){if(e.target.matches('input[type=color]')){var wrap=e.target.closest('.ddsw-color-input'),code=wrap&&wrap.querySelector('code');if(code)code.textContent=e.target.value;}refreshPreview();});
studio.addEventListener('change',refreshPreview);
if(preview){preview.addEventListener('click',function(e){var card=e.target.closest('.ddsw-preview-plan');if(!card)return;preview.querySelectorAll('.ddsw-preview-plan').forEach(function(p){p.classList.toggle('selected',p===card);});refreshPreview();});}
refreshPreview();
})();
