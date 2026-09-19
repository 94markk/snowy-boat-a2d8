(function(){
  'use strict';
  const root=document.querySelector('.dfr-studio'); if(!root||typeof DFRCatalog==='undefined')return;
  const state={service:'topup',cursor:'',categoryId:'',categoryName:'',loading:false};
  const $=(s,c=root)=>c.querySelector(s), $$=(s,c=root)=>Array.from(c.querySelectorAll(s));
  const toast=(msg,error=false)=>{const el=$('#dfr-toast');el.textContent=msg;el.classList.toggle('is-error',!!error);el.classList.add('is-show');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('is-show'),3200)};
  async function api(action,data={}){const body=new URLSearchParams({action,nonce:DFRCatalog.nonce,...data});const r=await fetch(DFRCatalog.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const j=await r.json();if(!j.success)throw new Error(j.data&&j.data.message?j.data.message:DFRCatalog.i18n.error);return j.data}
  $$('.dfr-tab').forEach(b=>b.addEventListener('click',()=>{$$('.dfr-tab').forEach(x=>x.classList.remove('is-active'));$$('.dfr-panel').forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');$(`[data-panel="${b.dataset.tab}"]`).classList.add('is-active')}));
  const requestedTab=new URLSearchParams(location.search).get('dfr_tab');if(requestedTab){const t=$(`.dfr-tab[data-tab="${requestedTab}"]`);if(t)t.click()}
  function initPricingMode(){
    const radios=$$('input[name="catalog_pricing_mode"]');
    if(!radios.length)return;
    const sync=()=>{
      const selected=radios.find(r=>r.checked);
      const mode=selected?selected.value:'manual';
      $$('.dfr-pricing-mode').forEach(card=>card.classList.toggle('is-selected',!!card.querySelector('input:checked')));
      const manual=$('[data-pricing-manual]');const auto=$('[data-pricing-auto]');
      if(manual)manual.hidden=mode!=='manual';
      if(auto){auto.hidden=mode!=='automatic';auto.setAttribute('aria-hidden',mode==='automatic'?'false':'true')}
    };
    radios.forEach(r=>r.addEventListener('change',sync));sync();
  }
  initPricingMode();
  function initBuilderMode(){
    const radios=$$('input[name$="_import_structure"]');
    if(!radios.length)return;
    const sync=()=>{radios.forEach(r=>{const card=r.closest('.dfr-builder-choice');if(card)card.classList.toggle('is-selected',r.checked)});};
    radios.forEach(r=>r.addEventListener('change',sync));sync();
  }
  initBuilderMode();
  async function checkStatus(){const btn=$('#dfr-check-status');btn.disabled=true;btn.textContent=DFRCatalog.i18n.loading;try{const d=await api('dfr_catalog_status');$('#dfr-stat-api').textContent=d.login||'Connected';$('#dfr-stat-plan').textContent=(d.plan?d.plan.toUpperCase()+' · ':'')+(d.subscriptionActive?'Subscription active':'Subscription status unavailable');$('#dfr-stat-balance').textContent=d.balance?`${d.balance} ${d.currency}`:'—';toast('Supplier API connected successfully.')}catch(e){toast(e.message,true)}finally{btn.disabled=false;btn.textContent='Test API'}}
  $('#dfr-check-status').addEventListener('click',checkStatus);
  function categoryMeta(c){return [c.region,c.platform,c.note].filter(Boolean).join(' · ')}
  function importStructure(service){
    const map=DFRCatalog.importStructures||{};
    if(map[service])return map[service];
    if(service==='topup'&&DFRCatalog.topupStructure)return DFRCatalog.topupStructure;
    return 'simple';
  }
  function variationAttribute(service){
    const map=DFRCatalog.variationAttributes||{};
    if(map[service])return map[service];
    if(service==='topup'&&DFRCatalog.variationAttribute)return DFRCatalog.variationAttribute;
    return service==='giftcard'?'Amount':(service==='gamekey'?'Option':'Package');
  }
  function renderCategories(items,append=false){const list=$('#dfr-category-list');if(!append)list.innerHTML='';if(!items.length&&!append){list.innerHTML='<div class="dfr-empty">No categories found.</div>';return}items.forEach(c=>{const b=document.createElement('button');b.type='button';b.className='dfr-category-item';b.dataset.id=c.id;b.innerHTML=`<strong>${esc(c.name)}</strong><small>${esc(categoryMeta(c)||c.id)}</small>`;b.addEventListener('click',()=>loadOffers(c,b));list.appendChild(b)})}
  async function loadCategories(force=false,append=false){if(state.loading)return;state.loading=true;const list=$('#dfr-category-list');if(!append)list.innerHTML='<div class="dfr-empty"><span class="dfr-spinner"></span></div>';try{const d=await api('dfr_catalog_categories',{service:state.service,cursor:append?state.cursor:'',force:force?'1':''});renderCategories(d.items,append);state.cursor=d.meta.next_cursor||'';$('#dfr-load-more').hidden=!d.meta.has_more}catch(e){list.innerHTML=`<div class="dfr-empty">${esc(e.message)}</div>`;toast(e.message,true)}finally{state.loading=false}}
  $$('.dfr-service').forEach(b=>b.addEventListener('click',()=>{if(state.service===b.dataset.service)return;$$('.dfr-service').forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');state.service=b.dataset.service;state.cursor='';state.categoryId='';$('#dfr-offer-list').innerHTML='<div class="dfr-empty dfr-empty-large">📦<strong>Select a category</strong><span>Supplier offers will appear here.</span></div>';$('#dfr-import-bar').hidden=true;loadCategories(false,false)}));
  $('#dfr-refresh-categories').addEventListener('click',()=>{state.cursor='';loadCategories(true,false)});$('#dfr-load-more').addEventListener('click',()=>loadCategories(false,true));
  $('#dfr-category-search').addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();$$('.dfr-category-item').forEach(x=>x.hidden=q&&!x.textContent.toLowerCase().includes(q))});
  async function loadOffers(cat,button){state.categoryId=cat.id;state.categoryName=cat.name;$$('.dfr-category-item').forEach(x=>x.classList.remove('is-active'));button.classList.add('is-active');const mode=importStructure(state.service)==='variable'?` · Variable product / ${variationAttribute(state.service)} variations`:'';$('#dfr-offer-head').innerHTML=`<div><h3>${esc(cat.name)}</h3><p>${esc(cat.id+mode)}</p></div>`;const list=$('#dfr-offer-list');list.innerHTML='<div class="dfr-empty dfr-empty-large"><span class="dfr-spinner"></span><strong>Loading supplier offers…</strong></div>';$('#dfr-import-bar').hidden=true;try{const d=await api('dfr_catalog_offers',{service:state.service,category_id:cat.id});state.categoryName=d.category_name;renderOffers(d.items,d.fields||[])}catch(e){list.innerHTML=`<div class="dfr-empty dfr-empty-large">⚠️<strong>${esc(e.message)}</strong></div>`;toast(e.message,true)}}
  function renderOffers(items,fields){const list=$('#dfr-offer-list');list.innerHTML='';if(!items.length){list.innerHTML='<div class="dfr-empty dfr-empty-large">📭<strong>No offers available</strong></div>';return}items.forEach(i=>{const label=document.createElement('label');label.className='dfr-offer-card';const stock=i.stock===null?'':` · Stock ${i.stock}`;const grouped=importStructure(state.service)==='variable';label.innerHTML=`<input type="checkbox" class="dfr-offer-check" value="${attr(i.id)}"><span><strong>${esc(i.name)}</strong><small>${esc(i.id+stock)}</small></span><span class="dfr-price"><b>Supplier $${esc(i.price_usd)} USD</b><em class="${i.product_id?'':'is-new'}">${i.product_id?(grouped?'Variation #':'Imported #')+i.product_id:'New'}</em></span>`;list.appendChild(label)});$('#dfr-import-bar').hidden=false;updateSelected();$$('.dfr-offer-check').forEach(x=>x.addEventListener('change',updateSelected));if(fields.length){$('#dfr-offer-head p').textContent=`${state.categoryId} · ${fields.map(f=>f.label).join(' + ')}`}}
  function updateSelected(){const n=$$('.dfr-offer-check:checked').length;const suffix=importStructure(state.service)==='variable'?' variation'+(n===1?'':'s')+' selected':' selected';$('#dfr-selected-count').textContent=`${n}${suffix}`}
  async function doImport(all){const ids=$$('.dfr-offer-check:checked').map(x=>x.value);if(!all&&!ids.length){toast(DFRCatalog.i18n.selectItems,true);return}const btn=all?$('#dfr-import-category'):$('#dfr-import-selected');btn.disabled=true;const old=btn.textContent;btn.textContent=DFRCatalog.i18n.importing;try{const d=await api('dfr_catalog_import',{service:state.service,category_id:state.categoryId,all:all?'1':'',item_ids:JSON.stringify(ids)});toast(d.message+(d.errors&&d.errors.length?' Some items had errors.':''),!!(d.errors&&d.errors.length));const active=$('.dfr-category-item.is-active');if(active)loadOffers({id:state.categoryId,name:state.categoryName},active)}catch(e){toast(e.message,true)}finally{btn.disabled=false;btn.textContent=old}}
  $('#dfr-import-selected').addEventListener('click',()=>doImport(false));$('#dfr-import-category').addEventListener('click',()=>doImport(true));
  $('#dfr-sync-imported').addEventListener('click',async()=>{const b=$('#dfr-sync-imported');b.disabled=true;const old=b.textContent;b.textContent=DFRCatalog.i18n.syncing;try{const d=await api('dfr_catalog_sync_imported');toast(d.message)}catch(e){toast(e.message,true)}finally{b.disabled=false;b.textContent=old}});
  function esc(v){const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML}function attr(v){return esc(v).replace(/"/g,'&quot;')}
  loadCategories(false,false);
})();
