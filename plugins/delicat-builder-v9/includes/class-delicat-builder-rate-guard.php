<?php
/**
 * 9.12.3 — server back-off shared by every Builder script.
 *
 * Hostinger's CDN / firewall (LiteSpeed, « server: hcdn ») answers a burst of uncached requests with its own
 * full-page « 403 Forbidden ». The Builder's own background requests (prefetch, region pages, session, live
 * sales, notifications, promotions) were enough to trip it on a busy page. This prints, first in <head> and
 * never delayed by LiteSpeed, a tiny window.DelicatBackoff:
 *  - trip( status ): a 403, 429 or 503 starts a 10-minute pause (localStorage, so every tab of the store sees
 *    it, plus a sessionStorage copy for browsers that refuse localStorage) and tells this tab and the others
 *    (the storage event) with a « delicat:backoff » event;
 *  - on(): true while the pause runs. Background requests check it and stay silent; nothing is retried
 *    automatically. Navigations and taps the shopper makes are never blocked.
 *
 * @package Delicat_Builder_V9
 */

defined( 'ABSPATH' ) || exit;

final class Delicat_Builder_V9_Rate_Guard {

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'print_guard' ), 0 );
	}

	public static function print_guard(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		?>
<script id="delicat-builder-v9-backoff" data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false">
(function(w){if(w.DelicatBackoff)return;var K='dlc-bo-until',M=6e5;
function get(){var v=0;try{v=+w.localStorage.getItem(K)||0}catch(e){}try{v=Math.max(v,+w.sessionStorage.getItem(K)||0)}catch(e){}return v}
function say(s){try{w.document.dispatchEvent(new CustomEvent('delicat:backoff',{detail:{status:s,until:get()}}))}catch(e){}}
w.DelicatBackoff={on:function(){return get()>Date.now()},until:get,
trip:function(s){s=+s;if(s!==403&&s!==429&&s!==503)return false;if(get()>Date.now())return true;var t=String(Date.now()+M);
try{w.localStorage.setItem(K,t)}catch(e){}try{w.sessionStorage.setItem(K,t)}catch(e){}say(s);return true}};
try{w.addEventListener('storage',function(e){if(e.key===K&&+e.newValue>Date.now()){try{w.sessionStorage.setItem(K,e.newValue)}catch(x){}say(0)}})}catch(e){}
})(window);
</script>
		<?php
	}
}

Delicat_Builder_V9_Rate_Guard::boot();
