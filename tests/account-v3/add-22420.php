<?php
$_SERVER['HTTP_HOST']=getenv('HOSTPORT'); $_SERVER['REQUEST_URI']='/';
require getenv('SITE').'/wp-load.php';
$o = get_option('dipstub_orders'); $y = date('Y');
$o = array_values(array_filter($o, function ($x) { return $x['id'] != 22420; }));
$o[] = ['id'=>22420,'customer'=>2,'status'=>'processing','total'=>780,'created'=>"$y-10-31 07:40:00",'paid'=>"$y-10-31 07:41:00",'method'=>'moncash','method_title'=>'MonCash','txn'=>'MC-7781','items'=>[['name'=>'Free Fire 520 diamants','subtotal'=>780,'meta'=>['Player ID'=>'998877665']]]];
update_option('dipstub_orders', $o); echo "ok\n";
