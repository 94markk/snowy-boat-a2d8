<?php
$_SERVER['HTTP_HOST']=getenv('HOSTPORT'); $_SERVER['REQUEST_URI']='/';
require getenv('SITE').'/wp-load.php';
update_option('permalink_structure','/%postname%/');
update_option('timezone_string','America/Port-au-Prince');
$acc = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'My account','post_name'=>'my-account','post_content'=>'[woocommerce_my_account]']);
$shop = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Boutique','post_name'=>'shop','post_content'=>'<p>shop</p>']);
update_option('woocommerce_myaccount_page_id',$acc); update_option('woocommerce_shop_page_id',$shop);
$uid = wp_insert_user(['user_login'=>'client1','user_pass'=>'ClientPass!2026','user_email'=>'client1@example.com','first_name'=>'Louis','last_name'=>'Marken Ricky','role'=>'subscriber']);
$other = wp_insert_user(['user_login'=>'client2','user_pass'=>'ClientPass!2026','user_email'=>'client2@example.com','role'=>'subscriber']);
update_user_meta($uid,'dip_email_verified','yes');
update_user_meta($uid,'_dipstub_balance','2450.75');
$y = date('Y');
update_option('dipstub_orders', [
 ['id'=>22447,'customer'=>$uid,'status'=>'completed','total'=>1885,'created'=>"$y-10-28 10:24:00",'paid'=>"$y-10-28 10:25:00",'completed'=>"$y-10-28 10:26:00",'txn'=>'MC-8841920077',
  'items'=>[['name'=>'Apple Gift Card USA 10 $','subtotal'=>1350,'meta'=>['_test_voucher'=>'X7KQ-9PLM-44TZ-R2D2','_dst2t_status'=>'completed','_dst2t_returns_voucher'=>'yes','_dst2t_vouchers'=>'1']],['name'=>'Free Fire 520 diamants','subtotal'=>620,'meta'=>['Player ID'=>'5123998877']]],
  'discount'=>85,'notes'=>[["$y-10-28 14:27:00",'Merci ! Votre carte Apple est livrée. Bon jeu 🎮']]],
 ['id'=>22431,'customer'=>$uid,'status'=>'processing','total'=>1678,'created'=>"$y-10-30 09:12:00",'paid'=>"$y-10-30 09:13:00",'method'=>'wallet','method_title'=>'Wallet',
  'items'=>[['name'=>'Free Fire 1080 diamants','subtotal'=>1678,'meta'=>['Player ID'=>'771200455','Player name'=>'RickyHT']]],'note'=>'Merci de livrer avant ce soir.'],
 ['id'=>22410,'customer'=>$uid,'status'=>'pending','total'=>2098,'created'=>"$y-10-31 08:05:00",'method'=>'natcash','method_title'=>'Natcash',
  'items'=>[['name'=>'Netflix Premium 1 mois','subtotal'=>2098]]],
 ['id'=>22405,'customer'=>$uid,'status'=>'on-hold','total'=>990,'created'=>"$y-10-29 18:40:00",'items'=>[['name'=>'PSN 10 $','subtotal'=>990]]],
 ['id'=>22400,'customer'=>$uid,'status'=>'cancelled','total'=>300,'created'=>"$y-10-20 12:00:00",'items'=>[['name'=>'PUBG 600 UC','subtotal'=>300]]],
 ['id'=>22390,'customer'=>$uid,'status'=>'refunded','total'=>500,'created'=>"$y-10-18 12:00:00",'paid'=>"$y-10-18 12:01:00",'completed'=>"$y-10-18 12:02:00",'refunded'=>500,'items'=>[['name'=>'Google Play 5 $','subtotal'=>500]]],
 ['id'=>22380,'customer'=>$uid,'status'=>'failed','total'=>750,'created'=>"$y-10-17 12:00:00",'items'=>[['name'=>'Steam 5 $','subtotal'=>750]]],
 ['id'=>22370,'customer'=>$other,'status'=>'completed','total'=>9999,'items'=>[['name'=>'Not yours','subtotal'=>9999]]],
]);
update_option('dipstub_force_phase', [22431 => 'sending']);
update_option('dipstub_products',[201=>['name'=>'Carte PSN 10 $','price'=>1350],202=>['name'=>'Free Fire 520 diamants','price'=>780]]);
update_user_meta($uid,'_delicat_builder_v9_favorites',[201,202]);
flush_rewrite_rules(false);
echo "acc=$acc uid=$uid other=$other\n";
