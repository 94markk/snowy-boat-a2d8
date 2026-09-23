(function(){
  'use strict';
  function ready(fn){if(document.readyState!=='loading')fn();else document.addEventListener('DOMContentLoaded',fn,{once:true});}
  ready(function(){
    document.querySelectorAll('.dip-security-center form[data-dip-confirm]').forEach(function(form){
      form.addEventListener('submit',function(event){
        var message=form.getAttribute('data-dip-confirm')||'';
        if(message&&typeof window.confirm==='function'&&!window.confirm(message))event.preventDefault();
      });
    });
  });
})();
