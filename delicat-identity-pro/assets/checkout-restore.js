(function(){
  'use strict';
  var key='dip_wc_checkout_fields_v2',ttl=20*60*1000,clearTimer=null;
  var allowed=['billing_first_name','billing_last_name','billing_company','billing_country','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_phone','billing_email','shipping_first_name','shipping_last_name','shipping_company','shipping_country','shipping_address_1','shipping_address_2','shipping_city','shipping_state','shipping_postcode'];
  function clear(){try{sessionStorage.removeItem(key);}catch(e){}}
  function save(){var data={};allowed.forEach(function(n){var e=document.querySelector('[name="'+n+'"]');if(e&&e.value)data[n]=String(e.value).slice(0,512);});try{sessionStorage.setItem(key,JSON.stringify({savedAt:Date.now(),data:data}));}catch(e){}}
  function restore(){var raw;try{raw=sessionStorage.getItem(key);}catch(e){}if(!raw)return;try{var payload=JSON.parse(raw),savedAt=Number(payload&&payload.savedAt||0),data=payload&&payload.data;if(!savedAt||Date.now()-savedAt>ttl||!data||typeof data!=='object'){clear();return;}Object.keys(data).forEach(function(n){if(allowed.indexOf(n)===-1)return;var e=document.querySelector('[name="'+n+'"]');if(e&&!e.value){e.value=String(data[n]).slice(0,512);e.dispatchEvent(new Event('change',{bubbles:true}));}});if(clearTimer)clearTimeout(clearTimer);clearTimer=setTimeout(clear,5000);}catch(e){clear();}}
  document.addEventListener('input',function(e){if(e.target&&allowed.indexOf(e.target.name)!==-1)save();});
  document.addEventListener('change',function(e){if(e.target&&allowed.indexOf(e.target.name)!==-1)save();});
  document.addEventListener('DOMContentLoaded',restore);setTimeout(restore,800);
})();
