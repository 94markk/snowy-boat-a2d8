<?php
$_SERVER['HTTP_HOST']=getenv('HOSTPORT'); $_SERVER['REQUEST_URI']='/';
require getenv('SITE').'/wp-load.php';
update_option('permalink_structure','/%postname%/');
$acc = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'My account','post_name'=>'my-account','post_content'=>'[woocommerce_my_account]']);
$shop = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Boutique','post_name'=>'shop','post_content'=>'<p>shop</p>']);
update_option('woocommerce_myaccount_page_id',$acc); update_option('woocommerce_shop_page_id',$shop);
$uid = wp_insert_user(['user_login'=>'client1','user_pass'=>'ClientPass!2026','user_email'=>'client1@example.com','first_name'=>'Louis','last_name'=>'Marken Ricky','role'=>'subscriber']);
update_user_meta($uid,'dip_email_verified','yes');
update_user_meta($uid,'_dipstub_balance', getenv('BAL') !== false ? getenv('BAL') : '2450.75');
update_option('dipstub_orders', getenv('NOORDERS') ? [] : [
 ['id'=>22447,'customer'=>$uid,'status'=>'completed','total'=>535,'name'=>'Apple USA 5 $'],
 ['id'=>22431,'customer'=>$uid,'status'=>'processing','total'=>1678,'name'=>'Free Fire 1080 diamants'],
 ['id'=>22410,'customer'=>$uid,'status'=>'pending','total'=>2098,'name'=>'Netflix Premium 1 mois'],
 ['id'=>22400,'customer'=>$uid,'status'=>'cancelled','total'=>300,'name'=>'PUBG 600 UC'],
]);
update_option('dipstub_products',[201=>['name'=>'Carte PSN 10 $','price'=>1350],202=>['name'=>'Free Fire 520 diamants','price'=>780],203=>['name'=>'Apple Gift Card 25 $','price'=>3300],204=>['name'=>'Netflix Premium','price'=>1950]]);
if (!getenv('NOFAVS')) update_user_meta($uid,'_delicat_builder_v9_favorites',[201,202,203,204]);
flush_rewrite_rules(false);
echo "acc=$acc uid=$uid\n";
