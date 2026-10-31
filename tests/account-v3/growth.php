<?php
$_SERVER['HTTP_HOST']='localhost:8960'; $_SERVER['REQUEST_URI']='/';
require getenv('SITE').'/wp-load.php';
global $wpdb; $t = $wpdb->prefix.'delicat_recharges';
$cols = $wpdb->get_col("SELECT name FROM pragma_table_info('$t')");
echo "cols: ", implode(',', $cols), "\n";
update_option('delicat_growth_enabled', 1);
update_option('delicat_growth_tiers', ['5000'=>2, '20000'=>3]);
$uid = get_user_by('login','client1')->ID;
$wpdb->insert($t, ['user_id'=>$uid,'montant'=>5000,'statut'=>'approuve']);
$wpdb->insert($t, ['user_id'=>$uid,'montant'=>2500,'statut'=>'approuve']);
$wpdb->insert($t, ['user_id'=>$uid,'montant'=>9000,'statut'=>'en_attente']);
echo $wpdb->last_error ? 'ERR '.$wpdb->last_error : "rows ok\n";
