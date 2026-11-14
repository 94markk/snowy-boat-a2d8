<?php
/** Product editor smoke test, with a legacy shared-package module already loaded. */
define('ABSPATH', __DIR__); $hooks = array(); $caps = true; $transients = array(); $objects = array(); $next = 1000; $synced = array();
function add_action($name,$cb,$priority=10,$argc=1) { global $hooks; $hooks[$name][] = array($cb,$priority); }
function add_filter($name,$cb,$priority=10,$argc=1) { add_action($name,$cb,$priority,$argc); }
function current_user_can($cap,$id=0) { global $caps; return $caps; }
function absint($v) { return abs((int)$v); }
function esc_html($v) { return htmlspecialchars((string)$v,ENT_QUOTES); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function admin_url($v) { return '/wp-admin/'.$v; }
function get_woocommerce_currency() { return 'HTG'; }
function wc_get_product($id) { global $objects; return $objects[$id]??false; }
function wp_nonce_field($a,$b,$ref=false) { echo '<input name="'.$b.'" value="good">'; }
function wp_verify_nonce($n,$a) { return $n==='good'; }
function wp_unslash($v) { return $v; }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function get_current_user_id() { return 7; }
function set_transient($k,$v,$ttl) { global $transients; $transients[$k]=$v; }
function get_transient($k) { global $transients; return $transients[$k]??false; }
function delete_transient($k) { global $transients; unset($transients[$k]); }
function sanitize_text_field($v) { return strip_tags($v); }
function sanitize_title($v) { return strtolower($v); }
function wc_format_decimal($v) { return (string)$v; }
function wc_delete_product_transients($id) {}
function add_meta_box(...$a) { global $box; $box=$a; }
class Attr {
 public $options=array('110','341'); function get_variation(){return true;} function is_taxonomy(){return false;} function get_options(){return $this->options;} function set_options($v){$this->options=$v;} function get_name(){return 'Package';}
}
class P {
 public $id; public $children=array(); public $attrs=array();
 function __construct($id,$parent=false){$this->id=$id;$this->attrs=$parent?array('package'=>new Attr):array('package'=>(string)$id);}
 function is_type($t){return $t===($this->id===100?'variable':'variation');} function get_children(){return $this->children;} function get_attributes(){return $this->attrs;} function set_attributes($a){$this->attrs=$a;} function get_price(){return '145';} function get_tax_status(){return 'taxable';} function get_tax_class(){return '';} function save(){return $this->id;}
}
class WC_Product_Variation extends P {
 public $meta=array(); public $parent; public $price;
 function __construct(){parent::__construct(0);} function set_parent_id($v){$this->parent=$v;} function set_status($v){} function set_virtual($v){} function set_regular_price($v){$this->price=$v;} function set_price($v){$this->price=$v;} function set_tax_status($v){} function set_tax_class($v){} function set_manage_stock($v){} function set_stock_status($v){} function update_meta_data($k,$v){$this->meta[$k]=$v;}
 function save(){global $next,$objects;$this->id=++$next;$objects[$this->id]=$this;$objects[$this->parent]->children[]=$this->id;return $this->id;}
}
class WC_Product_Variable { static function sync($id){global $synced;$synced[]=$id;} }
// Deliberately the old API only: the editor must not depend on a new shared class
// winning the plugin load order against Digital Gateway 4.23.0.
final class Delicat_Custom_Packages {
 const RECIPE='_delicat_package_recipe_v1';
 static function recipe($id){$p=wc_get_product($id);return $p instanceof WC_Product_Variation?($p->meta[self::RECIPE]??array()):array();}
 static function source($id){if(!in_array($id,array(110,341),true)){throw new RuntimeException('bad source');}return array('id'=>$id);}
 static function validate_recipe($r){foreach($r['parts'] as $p){self::source($p['id']);}return $r+array('parent'=>100,'gateway'=>'dst2t');}
}
require dirname(__DIR__).'/includes/class-delicat-package-product-editor.php';
function ok($v,$m){if(!$v){throw new RuntimeException('FAIL '.$m);}echo 'PASS '.$m."\n";}
$objects[100]=new P(100,true);$objects[100]->children=array(110,341);$objects[110]=new P(110);$objects[341]=new P(341);$post=(object)array('ID'=>100,'post_type'=>'product');
Delicat_Package_Product_Editor::boot();Delicat_Package_Product_Editor::boot();
ok(count($hooks['woocommerce_product_data_tabs'])===1,'product tab registered once alongside legacy shared module');
ok($hooks['woocommerce_process_product_meta'][0][1]===50,'inline save runs after WooCommerce core product save');
$tabs=Delicat_Package_Product_Editor::tabs(array('inventory'=>array()));ok(isset($tabs['inventory'],$tabs['delicat_packages']),'existing tabs preserved and custom tab added');
ok($tabs['delicat_packages']['class']===array('show_if_variable'),'tab shown for variable products');
ob_start();Delicat_Package_Product_Editor::panel();$html=ob_get_clean();
ok(strpos($html,'id="delicat_packages_product_data"')!==false,'tab target has matching rendered panel');
ob_start();Delicat_Package_Product_Editor::editor_box($post);$html=ob_get_clean();
ok(strpos($html,'delicat_inline_package_nonce')!==false&&strpos($html,'delicat_inline_package[parts][0][id]')!==false,'inline editor has nonce and component controls');
ok(strpos($html,'<form')===false&&strpos($html,' required')===false,'no nested form or required controls block ordinary product updates');
Delicat_Package_Product_Editor::meta_box($post);ok($box[0]==='delicat_packages_shortcut','sidebar shortcut registered');
ob_start();Delicat_Package_Product_Editor::shortcut($post);$html=ob_get_clean();ok(strpos($html,'product_id=100')!==false,'shortcut opens manager scoped to this product');
$_POST=array();Delicat_Package_Product_Editor::save_inline(100);ok(count($objects)===3,'ordinary saves do not create packages');
$_POST=array('delicat_inline_package'=>array('create'=>1),'delicat_inline_package_nonce'=>'bad');Delicat_Package_Product_Editor::save_inline(100);ok(count($objects)===3&&get_transient('delicat_package_editor_7')['type']==='error','invalid nonce blocks creation with feedback');
$data=array('create'=>1,'label'=>'220 Diamants','price'=>'290','parts'=>array(array('id'=>110,'qty'=>2)));
$_POST=array('delicat_inline_package'=>$data,'delicat_inline_package_nonce'=>'good');$caps=false;Delicat_Package_Product_Editor::save_inline(100);ok(count($objects)===3,'insufficient capability blocks creation');$caps=true;
Delicat_Package_Product_Editor::save_inline(100);$new=$objects[1001];ok($new->price==='290'&&$new->parent===100&&$new->meta[Delicat_Custom_Packages::RECIPE]['parts'][0]['qty']===2,'Update creates 220 as two 110 recharges at chosen price');
ok(in_array('220 Diamants',$objects[100]->attrs['package']->options,true)&&$synced===array(100),'new label added to parent options and prices synchronized');
ok(get_transient('delicat_package_editor_7')['type']==='success','successful creation gives persisted feedback');
Delicat_Package_Product_Editor::save_inline(100);ok(count($objects)===4,'duplicate save hooks do not create a second variation');
ob_start();Delicat_Package_Product_Editor::editor_box($post);$html=ob_get_clean();ok(strpos($html,'220 Diamants')!==false,'created package appears in labeled product box');
try{Delicat_Package_Product_Editor::create(100,$data);ok(false,'duplicate labels rejected');}catch(RuntimeException $e){ok(count($objects)===4,'duplicate labels rejected');}
$_GET=array('post'=>100);ob_start();Delicat_Package_Product_Editor::notices();$notice=ob_get_clean();ok(strpos($notice,'notice-success')!==false&&!get_transient('delicat_package_editor_7'),'notice consumed once on correct product');
ob_start();Delicat_Package_Product_Editor::banner($post);$banner=ob_get_clean();ok(strpos($banner,'Configurer les forfaits')!==false&&strpos($banner,'product_id=100')!==false,'visible top entry opens correct product manager');
echo "ALL PRODUCT EDITOR CHECKS PASSED\n";
