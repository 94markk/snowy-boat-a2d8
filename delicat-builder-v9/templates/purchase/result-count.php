<?php
/** French result count; WooCommerce supplies total/per_page/current. */
defined( 'ABSPATH' ) || exit;
if ( 0 === (int) $total ) { return; }
$first = ( (int) $current - 1 ) * (int) $per_page + 1;
$last = min( (int) $total, (int) $current * (int) $per_page );
?><p class="woocommerce-result-count" role="status"><?php
if ( 1 === (int) $total ) { echo '1 résultat'; }
elseif ( -1 === (int) $per_page || (int) $total <= (int) $per_page ) { echo esc_html( sprintf( '%s résultats', number_format_i18n( $total ) ) ); }
else { echo esc_html( sprintf( 'Affichage de %1$s–%2$s sur %3$s résultats', number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) ); }
?></p>
