<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Regression for the real missing-option symptom: a purchasable custom
 * variation was in data-product_variations but absent from attribute_package. */
define('ABSPATH',__DIR__);$objects=array();$meta=array();$options=array();$hooks=array();$invalidations=array();$caps=true;
function add_action($k,$cb,$p=10,$args=1){global $hooks;$hooks[$k][]=$cb;}
function add_filter($k,$cb,$p=10,$args=1){add_action($k,$cb,$p,$args);}
function absint($n){return abs((int)$n);}
function sanitize_title($s){return strtolower(str_replace(' ','-', $s));}
function wc_get_product($id){global $objects;return $objects[$id]??false;}
function get_post_meta($id,$key,$single=true){global $meta;return $meta[$id][$key]??'';}
function wp_get_post_parent_id($id){$p=wc_get_product($id);return $p?$p->get_parent_id():0;}
function current_user_can($c){global $caps;return $caps;}
function get_option($k,$d=false){global $options;return $options[$k]??$d;}
function update_option($k,$v,$autoload=false){global $options;$options[$k]=$v;}
function wc_delete_product_transients($id){global $invalidations;$invalidations[]=$id;}
function get_posts($args){global $objects,$meta;$ids=array();foreach($objects as $id=>$p){if($p->type!=='variation'||!isset($meta[$id]['_delicat_package_recipe_v1']))continue;if(isset($args['post_parent'])&&$p->parent!==$args['post_parent'])continue;if(!in_array($p->status,$args['post_status'],true))continue;$ids[]=$id;}sort($ids);return array_slice($ids,$args['offset']??0,$args['posts_per_page']===-1?null:$args['posts_per_page']);}
class Package_Test_Attribute {public $name='Package';public $values;public $taxonomy=false;function __construct($v){$this->values=$v;}function get_name(){return $this->name;}function get_variation(){return true;}function is_taxonomy(){return $this->taxonomy;}function get_options(){return $this->values;}function set_options($v){$this->values=$v;}}
class Product {
 public $id;public $parent;public $type;public $attrs;public $status='publish';public $saves=0;
 function __construct($id,$type,$attrs,$parent=0){$this->id=$id;$this->type=$type;$this->attrs=$attrs;$this->parent=$parent;}
 function is_type($v){return $this->type===$v;}function get_id(){return $this->id;}function get_parent_id(){return $this->parent;}function get_status(){return $this->status;}
 function get_attributes($ctx='view'){if($ctx==='view'&&$this->type==='variable'){return Delicat_Package_Selection::attributes($this->attrs,$this);}return $this->attrs;}
 function set_attributes($v){$this->attrs=$v;}function save(){$this->saves++;return $this->id;}
 function get_variation_attributes(){return array('Package'=>$this->get_attributes()['package']->get_options());}
}
require dirname(__DIR__).'/includes/class-delicat-package-selection.php';
function ok($v,$m){if(!$v)throw new RuntimeException('FAIL '.$m);echo 'PASS '.$m."\n";}
$original=array('100 + 10 Diamonds','310 + 31 Diamonds','520 + 52 Diamonds','1,060 + 106 Diamonds','2,180 + 218 Diamonds','5,600 + 560 Diamonds','Booyah Pass','Monthly Membership','Weekly Membership');
$objects[14692]=new Product(14692,'variable',array('package'=>new Package_Test_Attribute($original)));$parent=$objects[14692];
$objects[21469]=new Product(21469,'variation',array('package'=>'200 + 20 Diamonds'),14692);$meta[21469]['_delicat_package_recipe_v1']=array('parts'=>array(array('id'=>14693,'qty'=>2)));
Delicat_Package_Selection::boot();Delicat_Package_Selection::boot();ok(count($hooks['woocommerce_dropdown_variation_attribute_options_args'])===1,'selector compatibility boots once');
$before=$parent->get_attributes('edit')['package']->get_options();ok(!in_array('200 + 20 Diamonds',$before,true),'live symptom reproduced: parent options omit custom value');
$view=$parent->get_attributes()['package']->get_options();ok(count($view)===10&&array_slice($view,0,9)===$original&&$view[9]==='200 + 20 Diamonds','view adds exact plus-and-space value and preserves all nine native options');
ok($parent->get_attributes('edit')['package']->get_options()===$original&&$parent->saves===0,'view filter does not mutate stored attributes or write during page rendering');
$args=array('product'=>$parent,'attribute'=>'Package','options'=>$original,'show_option_none'=>'Choisir','selected'=>'');$fixed=Delicat_Package_Selection::dropdown($args);
ok($fixed['options']===$view&&$fixed['show_option_none']==='Choisir','native dropdown gains missing custom option without changing other arguments');
$defaults=$args;$defaults['options']=array();ok(Delicat_Package_Selection::dropdown($defaults)['options']===$view,'default option requests keep original values');
// Match exactly as WooCommerce compares local variation attributes; no fallback ID.
$available=array(array('variation_id'=>14693,'attributes'=>array('attribute_package'=>'100 + 10 Diamonds')),array('variation_id'=>21469,'attributes'=>array('attribute_package'=>'200 + 20 Diamonds')));
$selected=$fixed['options'][9];$matches=array_values(array_filter($available,function($r)use($selected){return $r['attributes']['attribute_package']===$selected;}));
ok(count($matches)===1&&$matches[0]['variation_id']===21469,'repaired selection matches existing custom variation 21469 exactly');
$payload_without_combo=array_slice($available,0,1);ok(!array_filter($payload_without_combo,function($r)use($selected){return $r['attributes']['attribute_package']===$selected;}),'no forced selection if WooCommerce excludes the custom variation');
Delicat_Package_Selection::persist($parent);ok($parent->get_attributes('edit')['package']->get_options()===$view,'next parent save persists missing option');
$parent->attrs['package']=new Package_Test_Attribute($original);Delicat_Package_Selection::persist($parent);ok(in_array($selected,$parent->attrs['package']->values,true),'catalog sync cannot strip custom parent options');
Delicat_Package_Selection::persist($parent);ok(count($parent->attrs['package']->values)===10,'repeated saves do not duplicate labels');
$other=new Product(999,'variable',array('package'=>new Package_Test_Attribute(array('Other'))));$otherArgs=array('product'=>$other,'attribute'=>'Package','options'=>array('Other'));ok(Delicat_Package_Selection::dropdown($otherArgs)===$otherArgs,'ordinary products unaffected');
$taxonomy=new Product(998,'variable',array('package'=>new Package_Test_Attribute(array('slug'))));$taxonomy->attrs['package']->taxonomy=true;$taxargs=array('product'=>$taxonomy,'attribute'=>'Package','options'=>array('slug'));ok(Delicat_Package_Selection::dropdown($taxargs)===$taxargs,'taxonomy selectors unaffected');
$objects[21470]=new Product(21470,'variation',array('package'=>'451 Diamonds'),14692);$objects[21470]->status='private';$meta[21470]['_delicat_package_recipe_v1']=$meta[21469]['_delicat_package_recipe_v1'];
Delicat_Package_Selection::after_save($objects[21470]);ok(!in_array('451 Diamonds',Delicat_Package_Selection::dropdown($args)['options'],true),'disabled custom options not newly exposed in dropdown');
$objects[21470]->status='publish';Delicat_Package_Selection::after_save($objects[21470]);ok(in_array('451 Diamonds',Delicat_Package_Selection::dropdown($args)['options'],true),'new/published package invalidates memo and appears without another request');
$parent->attrs['package']=new Package_Test_Attribute($original);$caps=false;Delicat_Package_Selection::repair_batch();ok($parent->saves===0,'repair requires store management capability');$caps=true;
Delicat_Package_Selection::repair_batch();ok($parent->saves===1&&get_option(Delicat_Package_Selection::MIGRATION)['done']===true,'upgrade repairs existing parent once and marks completed batch');
ok(in_array('200 + 20 Diamonds',$parent->attrs['package']->values,true)&&in_array('451 Diamonds',$parent->attrs['package']->values,true),'existing custom packages preserved by automatic repair');
Delicat_Package_Selection::repair_batch();ok($parent->saves===1,'completed migration does not write repeatedly');
ok(in_array(14692,$invalidations,true),'parent variation caches invalidated');
echo "ALL PACKAGE SELECTION CHECKS PASSED\n";
