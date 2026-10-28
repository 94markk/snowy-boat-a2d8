(function(){'use strict';document.addEventListener('click',function(e){var button=e.target.closest('[data-copy]');if(!button)return;var text=button.getAttribute('data-copy')||'';if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text);}else{var input=document.createElement('textarea');input.value=text;document.body.appendChild(input);input.select();document.execCommand('copy');input.remove();}var old=button.innerHTML;button.innerHTML='<code>'+text.replace(/</g,'&lt;')+'</code><strong>'+(window.dipUiStability?dipUiStability.copied:'Copié')+'</strong>';setTimeout(function(){button.innerHTML=old;},1200);});document.querySelectorAll('.dip-modern-admin form').forEach(function(form){form.addEventListener('submit',function(){if(form.classList.contains('is-saving'))return;form.classList.add('is-saving');});});})();
(function(){
  document.addEventListener('click',function(e){
    var toggle=e.target.closest('.dip-app-nav-toggle');
    if(!toggle)return;
    var shell=toggle.closest('.dip-app-shell-header');
    var open=shell.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded',open?'true':'false');
  });
})();
