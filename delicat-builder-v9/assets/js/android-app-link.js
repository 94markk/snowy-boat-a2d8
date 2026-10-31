(function(){'use strict';if(window.DelicatAndroidLinkBound)return;window.DelicatAndroidLinkBound=true;
function go(e){var url=window.DelicatAndroidPage;if(!url)return;if(e){e.preventDefault();e.stopImmediatePropagation();}window.location.assign(url);}
document.addEventListener('click',function(e){if(e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;var t=e.target&&e.target.closest?e.target.closest('a[href="#install-app"],[data-dlx-install],[data-db-v9-pwa-open]'):null;if(t)go(e);},true);
document.addEventListener('delicat:install-app',go,true);
})();
