(function(){
  'use strict';
  /*
   * Security Center forms (form[data-dip-sc-form]) and the confirmation forms
   * of the cards rendered inside it (2FA, Google Administrateur:
   * .dip-security-center form[data-dip-confirm]).
   * - In-page confirmation instead of window.confirm(), which some Android
   *   WebViews and in-app browsers answer « false » silently (the action was
   *   then impossible): a first click arms the button, a second one confirms.
   * - One submission per click: the button is disabled with a busy state.
   */
  var ARM_MS = 8000;
  var SELECTOR = 'form[data-dip-sc-form],.dip-security-center form[data-dip-confirm]';
  var DEFAULT_CONFIRM = 'Oui, confirmer';
  function ready(fn){if(document.readyState!=='loading')fn();else document.addEventListener('DOMContentLoaded',fn,{once:true});}
  function button(form){return form.querySelector('button[type="submit"],button:not([type])');}

  function disarm(form){
    var b=button(form), note=form.querySelector('.dip-sc-confirm');
    if(form._dipTimer){clearTimeout(form._dipTimer);form._dipTimer=null;}
    form.removeAttribute('data-dip-armed');
    if(b&&b.hasAttribute('data-dip-label')){b.textContent=b.getAttribute('data-dip-label');b.removeAttribute('data-dip-label');}
    if(b)b.classList.remove('is-confirming');
    if(note)note.parentNode.removeChild(note);
  }

  function arm(form){
    var b=button(form);
    if(!b)return;
    document.querySelectorAll('form[data-dip-armed]').forEach(function(other){if(other!==form)disarm(other);});
    form.setAttribute('data-dip-armed','1');
    b.setAttribute('data-dip-label',b.textContent);
    b.textContent=form.getAttribute('data-dip-confirm-label')||DEFAULT_CONFIRM;
    b.classList.add('is-confirming');
    var note=document.createElement('span');
    note.className='dip-sc-confirm';
    note.setAttribute('role','status');
    form.appendChild(note);
    // Filled after insertion so screen readers announce it.
    setTimeout(function(){note.textContent=form.getAttribute('data-dip-confirm')||'';},30);
    form._dipTimer=setTimeout(function(){disarm(form);},ARM_MS);
  }

  function busy(form){
    var b=button(form);
    form.setAttribute('data-dip-busy','1');
    if(form._dipTimer){clearTimeout(form._dipTimer);form._dipTimer=null;}
    if(!b)return;
    b.disabled=true;
    b.setAttribute('aria-busy','true');
  }

  function reset(form){
    var b=button(form);
    form.removeAttribute('data-dip-busy');
    disarm(form);
    if(b){b.disabled=false;b.removeAttribute('aria-busy');}
  }

  ready(function(){
    var forms=document.querySelectorAll(SELECTOR);
    if(!forms.length)return;
    forms.forEach(function(form){
      form.addEventListener('submit',function(event){
        if(form.getAttribute('data-dip-busy')==='1'){event.preventDefault();return;}
        if(form.hasAttribute('data-dip-confirm')&&form.getAttribute('data-dip-armed')!=='1'){
          // Cancelled submission: page-level confirm() listeners need not see it.
          event.preventDefault();
          event.stopPropagation();
          arm(form);
          return;
        }
        busy(form);
        // A later listener (a page-level confirm() answered « Annuler ») may
        // still cancel this submission: the button must not stay disabled.
        setTimeout(function(){if(event.defaultPrevented)reset(form);},0);
      });
      form.addEventListener('keydown',function(event){
        if((event.key==='Escape'||event.key==='Esc')&&form.getAttribute('data-dip-armed')==='1'){disarm(form);var b=button(form);if(b)b.focus();}
      });
    });
    // Back/forward cache: a page restored after a submission must be usable again.
    window.addEventListener('pageshow',function(event){
      if(event.persisted)forms.forEach(reset);
    });
  });
})();
