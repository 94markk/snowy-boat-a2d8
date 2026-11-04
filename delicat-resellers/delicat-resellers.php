<?php
/**
 * Plugin Name: Delicat Resellers
 * Description: Programme revendeur de Delicat Store : niveaux Premium, Gold et Silver, demande en ligne, dépôt de qualification par le système de recharge (jamais par un envoi entre portefeuilles), prix revendeur par produit, badge sur le compte et dépôt minimum chaque mois.
 * Version:     1.0.0
 * Author:      Delicat Store Haiti
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * Deposits are read from Delicat Recharge (table {prefix}delicat_recharges: approved and credited
 * recharges only); wallet-to-wallet transfers from its Send Money table ({prefix}delicat_transfers).
 * The reseller's level lives in user meta _dlr_tier and in a role per level (dlr_premium, dlr_gold,
 * dlr_silver), so caches that vary by role keep reseller prices apart from everyone else's.
 */
defined( 'ABSPATH' ) || exit;

const DLR_VERSION = '1.0.0';
const DLR_DB      = 1;
define( 'DLR_FILE', __FILE__ );
define( 'DLR_DIR', plugin_dir_path( __FILE__ ) );
define( 'DLR_URL', plugin_dir_url( __FILE__ ) );

require_once DLR_DIR . 'includes/core.php';
require_once DLR_DIR . 'includes/pricing.php';
require_once DLR_DIR . 'includes/front.php';
if ( is_admin() ) require_once DLR_DIR . 'includes/admin.php';

register_activation_hook( __FILE__, 'dlr_install' );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'dlr_daily' ); wp_unschedule_hook( 'dlr_recheck' ); } );
