(function(){
  'use strict';
  function str(key,fallback){var s=window.dipUiStability||{};return s[key]||fallback;}
  function legacyCopy(text){
    var input=document.createElement('textarea');input.value=text;input.setAttribute('readonly','');input.style.position='fixed';input.style.opacity='0';
    document.body.appendChild(input);input.select();var ok=false;try{ok=document.execCommand('copy');}catch(e){ok=false;}input.remove();return ok;
  }
  document.addEventListener('click',function(e){
    var button=e.target.closest('[data-copy]');
    // One copy at a time: a second click no longer records « Copié » as the label to restore.
    if(!button||button.__dipCopying)return;
    var text=button.getAttribute('data-copy')||'';
    button.__dipCopying=true;
    if(button.__dipLabel===undefined)button.__dipLabel=button.innerHTML;
    function done(ok){
      // Only a real copy is confirmed (a denied clipboard showed « Copié » too).
      button.innerHTML='<code>'+text.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</code><strong>'+(ok?str('copied','Copié'):str('copyFailed','Copie impossible'))+'</strong>';
      setTimeout(function(){button.innerHTML=button.__dipLabel;button.__dipCopying=false;},1200);
    }
    if(navigator.clipboard&&navigator.clipboard.writeText&&window.isSecureContext){navigator.clipboard.writeText(text).then(function(){done(true);},function(){done(legacyCopy(text));});}
    else done(legacyCopy(text));
  });
  // After the form's own handlers (onsubmit="return confirm(…)"): a cancelled
  // submission no longer leaves the primary buttons disabled.
  document.addEventListener('submit',function(e){
    var form=e.target;
    if(e.defaultPrevented||!form||!form.closest||!form.closest('.dip-modern-admin'))return;
    form.classList.add('is-saving');
    setTimeout(function(){form.classList.remove('is-saving');},8000);
  });
  window.addEventListener('pageshow',function(){Array.prototype.forEach.call(document.querySelectorAll('form.is-saving'),function(form){form.classList.remove('is-saving');});});
})();
(function(){
  document.addEventListener('click',function(e){
    var toggle=e.target.closest('.dip-app-nav-toggle');
    if(!toggle)return;
    var shell=toggle.closest('.dip-app-shell-header');
    var open=shell.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded',open?'true':'false');
  });
})();
