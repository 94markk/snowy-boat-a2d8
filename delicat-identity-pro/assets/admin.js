(function(){
  'use strict';

  function copyText(button, target){
    if(!target) return;
    var text=(target.textContent||'').trim();
    if(!text) return;
    var old=button.textContent;
    var done=function(){button.textContent='Copié ✓';button.classList.add('is-copied');setTimeout(function(){button.textContent=old;button.classList.remove('is-copied');},1500);};
    if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done).catch(function(){});return;}
    var area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','');area.style.cssText='position:fixed;left:-9999px;opacity:0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(area);
  }

  document.addEventListener('click',function(event){
    var copy=event.target.closest('[data-dip-copy]');
    if(copy){event.preventDefault();copyText(copy,document.querySelector(copy.getAttribute('data-dip-copy')));return;}
    var preset=event.target.closest('.dip-redirect-preset');
    if(preset){event.preventDefault();var key=preset.getAttribute('data-target');var value=preset.getAttribute('data-value')||'';var input=document.querySelector('input[name="dglp_settings['+key+']"]');if(input){input.value=value;input.dispatchEvent(new Event('change',{bubbles:true}));input.focus();}}
  });

  var nav=document.querySelector('.dip-admin-nav');
  if(nav){
    var links=[].slice.call(nav.querySelectorAll('a[href^="#"]'));
    links.forEach(function(link){link.addEventListener('click',function(){links.forEach(function(a){a.classList.remove('is-active');});link.classList.add('is-active');});});
    if('IntersectionObserver' in window){
      var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(!entry.isIntersecting)return;var id='#'+entry.target.id;links.forEach(function(a){a.classList.toggle('is-active',a.getAttribute('href')===id);});});},{rootMargin:'-25% 0px -65% 0px'});
      links.forEach(function(link){var el=document.querySelector(link.getAttribute('href'));if(el)observer.observe(el);});
    }
  }

  var form=document.getElementById('dip-settings-form');
  var saveBar=document.querySelector('.dip-save-bar');
  if(form&&saveBar){
    var dirty=false;
    var markDirty=function(){if(dirty)return;dirty=true;saveBar.classList.add('is-dirty');var span=saveBar.querySelector('span');if(span)span.textContent='Modifications non enregistrées';};
    form.addEventListener('input',markDirty);
    form.addEventListener('change',markDirty);
    form.addEventListener('submit',function(){dirty=false;saveBar.classList.remove('is-dirty');saveBar.classList.add('is-saving');var btn=saveBar.querySelector('[type="submit"]');if(btn){btn.disabled=true;btn.value='Enregistrement…';}});
    window.addEventListener('beforeunload',function(e){if(!dirty)return;e.preventDefault();e.returnValue='';});
  }

  document.querySelectorAll('.dip-admin-wrap .form-table').forEach(function(table){
    var rows=table.querySelectorAll(':scope > tbody > tr');
    rows.forEach(function(row){var heading=row.querySelector('th[colspan="2"] h2');if(heading)row.classList.add('dip-table-section-row');});
  });
})();
