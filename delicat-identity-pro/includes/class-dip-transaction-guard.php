<?php
defined('ABSPATH') || exit;

/** Step-up approval bound to the exact Exchange Studio form, quote and WP session. */
final class DIP_Transaction_Guard {
    const TTL=600;
    const CLEANUP_HOOK='dip_financial_guard_cleanup';
    public static function init() {
        foreach(['des_submit','des_withdraw_submit'] as $action)add_action('wp_ajax_'.$action,[__CLASS__,'guard'], -100);
        add_action('template_redirect',[__CLASS__,'review'], -20);
        add_action('admin_post_dip_transaction_approve',[__CLASS__,'approve']);
        add_filter('dip_self_service_actions',static function($a){$a[]='dip_transaction_approve';return $a;});
        add_action('wp_enqueue_scripts',[__CLASS__,'assets'],100);
        add_action(self::CLEANUP_HOOK,[__CLASS__,'cleanup']);
        if(!wp_next_scheduled(self::CLEANUP_HOOK))wp_schedule_event(time()+HOUR_IN_SECONDS,'hourly',self::CLEANUP_HOOK);
    }
    public static function binding() { return hash_hmac('sha256',get_current_user_id().'|'.wp_get_session_token(),wp_salt('secure_auth')); }
    public static function canonical($data,$depth=0) {
        if($depth>3)throw new InvalidArgumentException('Données trop imbriquées.');
        if(is_array($data)){if(count($data)>60)throw new InvalidArgumentException('Trop de champs.');ksort($data);$out=[];foreach($data as $k=>$v){if(!is_string($k)&&!is_int($k))throw new InvalidArgumentException('Champ invalide.');$out[$k]=self::canonical($v,$depth+1);}return $out;}
        if(!is_scalar($data)&&$data!==null)throw new InvalidArgumentException('Valeur invalide.');
        $value=(string)$data;if(strlen($value)>4096)throw new InvalidArgumentException('Champ trop long.');return $value;
    }
    public static function fingerprint(array $body,array $quote) { unset($body['nonce'],$body['dip_tx_approval']);return hash_hmac('sha256',wp_json_encode([self::canonical($body),self::canonical($quote)]),wp_salt('secure_auth')); }
    private static function key($id) { return 'dip_tx_'.hash('sha256',$id); }
    public static function load($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{48}$/D',$id))return false;
        $s=get_transient(self::key($id));
        return is_array($s)&&(int)$s['uid']===get_current_user_id()&&(int)$s['expires']>time()&&hash_equals($s['session'],self::binding()) ? $s : false;
    }
    public static function authorized(array $state,$hash) { return !empty($state['approved']) && hash_equals((string)$state['hash'],(string)$hash) && hash_equals((string)$state['session'],self::binding()) && (int)$state['uid']===get_current_user_id() && (int)$state['expires']>time(); }
    public static function guard() {
        check_ajax_referer('des_frontend','nonce');
        if(!is_user_logged_in()||is_wp_error(DIP_Account_Sync::login_guard(get_current_user_id())))wp_send_json_error(['message'=>'Connexion autorisée requise.'],403);
        if(DIP_Protection::setup_required())wp_send_json_error(['message'=>'Configurez votre 2FA administrateur.'],403);
        $body=wp_unslash($_POST);if(strlen(wp_json_encode($body))>20000)wp_send_json_error(['message'=>'Demande trop volumineuse.'],400);
        $action=isset($body['action'])&&is_string($body['action'])?$body['action']:'';
        if(!in_array($action,['des_submit','des_withdraw_submit'],true))wp_send_json_error(['message'=>'Action invalide.'],400);
        $amount=isset($body['amount'])&&is_scalar($body['amount'])?round((float)str_replace(',','.',(string)$body['amount']),8):0;
        if(!class_exists('DES_Core'))wp_send_json_error(['message'=>'Module financier indisponible.'],503);
        if($action==='des_withdraw_submit')$quote=DES_Core::withdrawal_quote(sanitize_key(is_scalar($body['method']??null)?$body['method']:''),$amount);
        else $quote=DES_Core::quote(sanitize_key(is_scalar($body['from']??null)?$body['from']:''),sanitize_key(is_scalar($body['to']??null)?$body['to']:''),$amount);
        if(is_wp_error($quote))wp_send_json_error(['message'=>$quote->get_error_message()],400);
        $quote=array_intersect_key($quote,array_flip(['request_type','from','to','from_label','to_label','product_key','product_title','amount','fee','fee_mode','pay_amount','rate','receive','from_unit','to_unit','min','max','collection_account']));
        try{$hash=self::fingerprint($body,$quote);}catch(InvalidArgumentException $e){wp_send_json_error(['message'=>'Données de transaction invalides.'],400);}
        $id=isset($body['dip_tx_approval'])&&is_string($body['dip_tx_approval'])?$body['dip_tx_approval']:'';$state=self::load($id);
        if($state&&self::authorized($state,$hash)){
            $used='dip_tx_used_'.hash('sha256',$id).'_'.$state['expires'];
            if(!add_option($used,1,'',false))wp_send_json_error(['message'=>'Cette autorisation a déjà été utilisée. Confirmez une nouvelle demande.'],409);
            delete_transient(self::key($id));DIP_Audit::record('financial_request_authorized','notice',get_current_user_id(),['kind'=>$action]);return;
        }
        if(DIP_Lockout::counter_hit('dip_tx_start_'.get_current_user_id(),600)>30)wp_send_json_error(['message'=>'Trop de demandes de confirmation. Réessayez plus tard.'],429);
        $id=bin2hex(random_bytes(24));unset($body['nonce'],$body['dip_tx_approval']);
        $cipher=DIP_Crypto::encrypt(wp_json_encode(['body'=>$body,'quote'=>$quote]));if($cipher==='')wp_send_json_error(['message'=>'Confirmation sécurisée indisponible.'],503);
        $s=['uid'=>get_current_user_id(),'session'=>self::binding(),'hash'=>$hash,'expires'=>time()+self::TTL,'approved'=>false,'proof_before'=>DIP_Reauth::proof_generation(),'data'=>$cipher];
        if(!set_transient(self::key($id),$s,self::TTL))wp_send_json_error(['message'=>'Impossible de préparer la confirmation.'],503);
        wp_send_json_error(['code'=>'dip_transaction_confirmation','message'=>'Confirmez votre identité et les informations de la transaction avant l’envoi.','approval'=>$id,'confirmation_url'=>add_query_arg('dip_confirm_transaction',$id,home_url('/'))],428);
    }
    public static function review() {
        if(!isset($_GET['dip_confirm_transaction']))return;
        if(!is_user_logged_in())auth_redirect();$id=is_string($_GET['dip_confirm_transaction'])?$_GET['dip_confirm_transaction']:'';$s=self::load($id);
        nocache_headers();header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');header('X-Robots-Tag: noindex, nofollow');
        if(!$s)wp_die('Cette demande a expiré ou appartient à une autre session. Revenez à votre formulaire pour recommencer.','',['response'=>410]);
        $data=json_decode(DIP_Crypto::decrypt($s['data']),true);if(!is_array($data))wp_die('Demande illisible.','',['response'=>503]);
        wp_enqueue_style('dip-customer-dashboard',DIP_URL.'assets/customer-dashboard.css',[],DIP_VERSION);
        get_header();echo '<main style="max-width:680px;margin:24px auto;padding:20px"><h1>Confirmer la transaction</h1><p>Vérifiez le montant et les coordonnées. Gardez votre formulaire d’origine ouvert.</p><dl>';
        $q=$data['quote'];$body=$data['body'];
        $fields=['Type'=>($body['action']==='des_withdraw_submit'?'Retrait Wallet':'Échange'),'Débit / paiement'=>($q['pay_amount']??$q['amount']??'').' '.($q['from_unit']??''),'À recevoir'=>($q['receive']??'').' '.($q['to_unit']??''),'Destination'=>$q['to_label']??$body['method']??$body['to']??''];
        foreach(['receiver_name'=>'Bénéficiaire','receiver_phone'=>'Téléphone du bénéficiaire'] as $key=>$label)if(!empty($body[$key]))$fields[$label]=$body[$key];
        if(!empty($body['receive'])&&is_array($body['receive']))$fields['Coordonnées de réception']=implode(' · ',array_map(static function($v){return is_scalar($v)?(string)$v:'';},$body['receive']));
        foreach($fields as $label=>$value)echo '<dt><strong>'.esc_html($label).'</strong></dt><dd>'.esc_html($value).'</dd>';echo '</dl>';
        if(!empty($s['approved']))echo '<p role="status">Transaction autorisée. Revenez au formulaire d’origine et appuyez de nouveau sur Envoyer. Toute modification exigera une nouvelle validation.</p>';
        else{
            $proof=DIP_Reauth::proof_generation();
            $ready=DIP_Reauth::is_recent() && $proof!=='' && !hash_equals((string)$s['proof_before'],$proof);
            if(!$ready){echo '<p>Effectuez une nouvelle confirmation d’identité.</p>'.DIP_Reauth::render_form(true,add_query_arg('dip_confirm_transaction',$id,home_url('/')));DIP_Passkeys::enqueue_runtime();}
            else echo '<p>Identité confirmée. Vérifiez les détails puis autorisez cette transaction.</p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dip_transaction_approve"><input type="hidden" name="transaction" value="'.esc_attr($id).'">';wp_nonce_field('dip_tx_'.$id);echo '<p><label><input name="approve_details" type="checkbox" value="1" required> Je confirme le montant et le bénéficiaire affichés.</label></p><button>Autoriser cette transaction</button></form>';}
        echo '</main>';get_footer();exit;
    }
    public static function approve() {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_user_logged_in())wp_die('Connexion requise.','',['response'=>403]);
        $id=isset($_POST['transaction'])&&is_string($_POST['transaction'])?wp_unslash($_POST['transaction']):'';check_admin_referer('dip_tx_'.$id);$s=self::load($id);
        if(!$s||is_wp_error(DIP_Account_Sync::login_guard(get_current_user_id()))||($_POST['approve_details']??'')!=='1')wp_die('Demande invalide.','',['response'=>403]);
        $proof=DIP_Reauth::proof_record();$now=(string)($proof['id']??'');
        if(!DIP_Reauth::is_recent()||$now===''||hash_equals((string)$s['proof_before'],$now))wp_die('Effectuez une nouvelle confirmation d’identité sur la page précédente, puis autorisez la transaction.','',['response'=>428]);
        if(!empty($s['approved'])){wp_safe_redirect(add_query_arg('dip_confirm_transaction',$id,home_url('/')));exit;}
        $proof_claim='dip_tx_used_'.hash('sha256','proof|'.self::binding().'|'.$now).'_'.(int)$proof['expires'];
        if(!add_option($proof_claim,1,'',false))wp_die('Cette confirmation d’identité a déjà autorisé une transaction. Confirmez à nouveau votre identité.', '', ['response'=>409]);
        $s['approved']=true;if(!set_transient(self::key($id),$s,max(1,$s['expires']-time())))wp_die('Impossible de sauvegarder la confirmation.','',['response'=>503]);
        wp_safe_redirect(add_query_arg('dip_confirm_transaction',$id,home_url('/')));exit;
    }
    public static function assets() { if(is_user_logged_in()&&class_exists('DES_Ajax'))wp_enqueue_script('dip-financial-confirmation',DIP_URL.'assets/financial-confirmation.js',[],DIP_VERSION,true); }
    public static function cleanup() {
        global $wpdb;
        $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name,'_',-1) AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT 1000",$wpdb->esc_like('dip_tx_used_').'%',time()));
        foreach((array)$names as $name)delete_option($name);
    }
}
