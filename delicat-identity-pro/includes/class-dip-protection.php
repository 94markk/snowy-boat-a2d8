<?php
defined('ABSPATH') || exit;

/** Mandatory administrator MFA, session visibility, alerts and deployment evidence. */
final class DIP_Protection {
    const PAGE = 'dip-admin-security';
    const EVIDENCE = 'dip_security_deployment_evidence';
    public static function init() {
        add_action('admin_menu', [__CLASS__,'menu']);
        add_action('admin_init', [__CLASS__,'admin_gate'], -50);
        add_filter('rest_pre_dispatch', [__CLASS__,'rest_gate'], 0, 3);
        add_action('woocommerce_account_security-sessions_endpoint',[__CLASS__,'sessions_screen']);
        add_action('init',[__CLASS__,'endpoint']);
        add_filter('woocommerce_get_query_vars',[__CLASS__,'query_vars']);
        add_filter('woocommerce_account_menu_items',[__CLASS__,'account_menu'],41);
        add_action('admin_post_dip_security_evidence',[__CLASS__,'save_evidence']);
        add_action('admin_post_dip_security_mail_probe',[__CLASS__,'mail_probe']);
        add_action('admin_post_dip_security_mail_confirm',[__CLASS__,'mail_confirm']);
        add_filter('dip_admin_only_actions',[__CLASS__,'admin_actions']);
        add_action('dip_customer_sessions_revoked',[__CLASS__,'revocation_alert'],10,2);
    }
    public static function admin_actions($items) { return array_merge((array)$items,['dip_security_evidence','dip_security_mail_probe','dip_security_mail_confirm']); }
    public static function required($uid=0) { return $uid ? user_can(absint($uid),'manage_options') : current_user_can('manage_options'); }
    public static function setup_required() { return is_user_logged_in() && self::required() && !DIP_Two_Factor::is_enabled(); }
    public static function url($status='') { return add_query_arg(array_filter(['page'=>self::PAGE,'dip_protection_status'=>$status]),admin_url('admin.php')); }
    public static function admin_gate() {
        if (!self::setup_required()) return;
        $page=isset($_GET['page'])&&is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $script=basename((string)($_SERVER['PHP_SELF']??''));
        $action=isset($_REQUEST['action'])&&is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (!wp_doing_ajax() && $page===self::PAGE && $script==='admin.php' && $action==='') return;
        if ($script==='admin-post.php' && in_array($action,['dip_2fa_begin','dip_2fa_confirm','dip_reauth_confirm','dip_reauth_email_code'],true)) return;
        if (wp_doing_ajax()) wp_send_json_error(['code'=>'dip_admin_mfa_required','message'=>'Configurez la 2FA administrateur avant de continuer.','setup_url'=>self::url()],403);
        wp_safe_redirect(self::url('setup_required')); exit;
    }
    public static function rest_gate($result,$server,$request) {
        if ($result!==null || !self::setup_required()) return $result;
        return new WP_Error('dip_admin_mfa_required','Configurez la 2FA administrateur avant d’utiliser les API authentifiées.',['status'=>403,'setup_url'=>self::url()]);
    }
    public static function menu() { add_menu_page('Protection Identity','Protection Identity','manage_options',self::PAGE,[__CLASS__,'screen'],'dashicons-shield-alt',59); }
    public static function endpoint() { add_rewrite_endpoint('security-sessions',EP_ROOT|EP_PAGES); }
    public static function query_vars($vars) { $vars['security-sessions']='security-sessions';return $vars; }
    public static function account_menu($items) { if(is_user_logged_in())$items['security-sessions']='Sessions et connexions';return $items; }
    public static function require_admin($nonce) {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !current_user_can('manage_options')) wp_die('Accès refusé.','',['response'=>403]);
        check_admin_referer($nonce);
        if (!DIP_Two_Factor::is_enabled() || !DIP_Reauth::is_recent()) { wp_safe_redirect(self::url('confirm_identity'));exit; }
    }
    private static function post($key) { return isset($_POST[$key])&&is_scalar($_POST[$key]) ? (string)wp_unslash($_POST[$key]) : ''; }
    public static function checklist() { return ['iphone'=>'Safari iPhone : connexion, passkey, récupération','android'=>'Chrome Android et application : connexion et révocation','google'=>'Google : connexion et retour vers la boutique','magic'=>'Lien magique reçu et utilisable une seule fois','password'=>'Mot de passe et récupération : changement puis reconnexion','photo'=>'Photo : ajout, remplacement et suppression','ban'=>'Bannissement temporaire, permanent et réautorisation','money'=>'Retrait : validation du montant et du bénéficiaire','mail'=>'Messages de sécurité réellement reçus','restore'=>'Restauration d’une sauvegarde en environnement de test']; }
    public static function save_evidence() {
        self::require_admin('dip_security_evidence');
        $items=[];$posted=isset($_POST['checks'])&&is_array($_POST['checks'])?$_POST['checks']:[];
        foreach(self::checklist() as $id=>$label)if(isset($posted[$id])&&$posted[$id]==='1')$items[]=$id;
        $data=['actor'=>get_current_user_id(),'at'=>time(),'checks'=>$items,'backup_provider'=>substr(sanitize_text_field(self::post('backup_provider')),0,120),'backup_date'=>substr(sanitize_text_field(self::post('backup_date')),0,16),'restore_date'=>substr(sanitize_text_field(self::post('restore_date')),0,16),'note'=>substr(sanitize_textarea_field(self::post('note')),0,1000)];
        update_option(self::EVIDENCE,$data,false);
        DIP_Audit::record('deployment_evidence_saved','notice',get_current_user_id(),['checked'=>count($items)]);
        wp_safe_redirect(self::url('evidence_saved'));exit;
    }
    public static function mail_probe() {
        self::require_admin('dip_security_mail_probe');$uid=get_current_user_id();
        if(DIP_Lockout::counter_hit('dip_mail_probe_'.$uid,900)>3)wp_die('Trop de demandes. Réessayez dans 15 minutes.','',['response'=>429]);
        $code=(string)random_int(100000,999999);
        update_user_meta($uid,'_dip_mail_probe',['hash'=>wp_hash_password($code),'expires'=>time()+600]);
        $u=get_userdata($uid);$sent=wp_mail($u->user_email,'Delicat — test de réception','Code de confirmation de réception : '.$code.'. Expire dans 10 minutes. Saisissez-le sur votre page Protection Identity.');
        if(!$sent)delete_user_meta($uid,'_dip_mail_probe');
        wp_safe_redirect(self::url($sent?'mail_sent':'mail_failed'));exit;
    }
    public static function mail_confirm() {
        self::require_admin('dip_security_mail_confirm');$uid=get_current_user_id();
        if(DIP_Lockout::counter_hit('dip_mail_verify_'.$uid,600)>5)wp_die('Trop de tentatives.','',['response'=>429]);
        $record=get_user_meta($uid,'_dip_mail_probe',true);$code=self::post('mail_code');
        $ok=is_array($record)&&(int)$record['expires']>=time()&&preg_match('/^\d{6}$/D',$code)&&wp_check_password($code,$record['hash']);
        if($ok)$ok=delete_user_meta($uid,'_dip_mail_probe',$record);
        if($ok){update_user_meta($uid,'_dip_mail_confirmed_at',time());}
        wp_safe_redirect(self::url($ok?'mail_confirmed':'mail_invalid'));exit;
    }
    public static function screen() {
        if(!current_user_can('manage_options'))return;
        wp_enqueue_style('dip-customer-dashboard',DIP_URL.'assets/customer-dashboard.css',[],DIP_VERSION);
        $uid=get_current_user_id();$e=(array)get_option(self::EVIDENCE,[]);$checks=$e['checks']??[];
        echo '<div class="wrap"><h1>Protection Identity</h1><p>Configuration administrateur, demandes d’e-mail, réception des messages et vérifications de mise en service.</p>';
        $status=isset($_GET['dip_protection_status'])&&is_string($_GET['dip_protection_status'])?sanitize_key($_GET['dip_protection_status']):'';
        $notices=['email_requested'=>'Demande enregistrée. Le client doit recevoir le code à sa nouvelle adresse avant la validation.', 'email_approved'=>'Adresse modifiée et sessions révoquées.', 'setup_required'=>'La 2FA est obligatoire : configurez votre application Authenticator pour accéder à l’administration.','confirm_identity'=>'Confirmez votre identité dans la carte ci-dessous puis recommencez.','evidence_saved'=>'Vérifications déclarées enregistrées.','mail_sent'=>'Message accepté par le transport. Vérifiez votre boîte et saisissez le code reçu pour confirmer la livraison.','mail_failed'=>'Le transport a refusé le message. Vérifiez la configuration d’envoi.','mail_confirmed'=>'Réception du message confirmée.','mail_invalid'=>'Code invalide ou expiré.'];
        if(isset($notices[$status]))echo '<div class="notice notice-info"><p>'.esc_html($notices[$status]).'</p></div>';
        echo '<div style="max-width:850px">'.DIP_Two_Factor::render_settings($uid).DIP_Reauth::render_form().'</div>';
        if(!DIP_Two_Factor::is_enabled($uid)){echo '<p>Après activation, conservez les codes de récupération hors du site.</p></div>';return;}
        echo '<h2>État technique</h2><ul>';
        $health=['HTTPS'=>is_ssl(),'Vérification cryptographique'=>function_exists('openssl_verify'),'Éditeur photo'=>function_exists('wp_get_image_editor')&&(extension_loaded('gd')||extension_loaded('imagick')),'2FA administrateur'=>DIP_Two_Factor::is_enabled($uid),'Protection Exchange Studio'=>class_exists('DES_Ajax'),'Session WordPress par défaut'=>apply_filters('session_token_manager','WP_User_Meta_Session_Tokens')==='WP_User_Meta_Session_Tokens'];
        foreach($health as $label=>$ok)echo '<li>'.($ok?'✓ ':'À vérifier : ').esc_html($label).'</li>';echo '</ul>';
        echo '<h2>Vérifier la réception des e-mails</h2><p>Un envoi accepté ne garantit pas sa réception. Le code reçu permet de confirmer ce test.</p>';
        foreach(['probe'=>'Envoyer un test à mon adresse','confirm'=>'Confirmer le code reçu'] as $kind=>$label){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('dip_security_mail_'.$kind);echo '<input type="hidden" name="action" value="dip_security_mail_'.$kind.'">';if($kind==='confirm')echo '<label>Code reçu <input name="mail_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label> ';echo '<button class="button">'.esc_html($label).'</button></form>';}
        $confirmed=(int)get_user_meta($uid,'_dip_mail_confirmed_at',true);echo '<p>Dernière réception confirmée : '.esc_html($confirmed?wp_date('d/m/Y H:i',$confirmed):'pas encore testée').'</p>';
        echo '<h2>Mise en service et sauvegardes externes</h2><p>Ces éléments sont déclarés par un administrateur : ils ne sont pas vérifiés automatiquement. Configurez les sauvegardes externes chez votre hébergeur, puis restaurez une copie de test avant de cocher la dernière case.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('dip_security_evidence');echo '<input type="hidden" name="action" value="dip_security_evidence">';
        foreach(self::checklist() as $id=>$label)echo '<p><label><input type="checkbox" name="checks['.esc_attr($id).']" value="1" '.checked(in_array($id,$checks,true),true,false).'> '.esc_html($label).'</label></p>';
        foreach(['backup_provider'=>'Emplacement / fournisseur externe (sans identifiants)','backup_date'=>'Date de la dernière sauvegarde vérifiée','restore_date'=>'Date du dernier essai de restauration'] as $key=>$label)echo '<p><label>'.esc_html($label).' <input type="'.($key==='backup_provider'?'text':'date').'" name="'.esc_attr($key).'" value="'.esc_attr($e[$key]??'').'"></label></p>';
        echo '<p><label>Compte rendu du test <textarea name="note" rows="3" cols="60" maxlength="1000">'.esc_textarea($e['note']??'').'</textarea></label></p><button class="button button-primary">Enregistrer mes vérifications</button></form>';
        if(class_exists('DIP_Email_Requests'))DIP_Email_Requests::admin_list();echo '</div>';
    }
    public static function session_rows($uid) {
        $raw=get_user_meta(absint($uid),'session_tokens',true);$current=hash('sha256',wp_get_session_token());$rows=[];
        foreach(is_array($raw)?$raw:[] as $hash=>$s){if(!is_array($s)||(int)($s['expiration']??0)<=time())continue;$description=DIP_Devices::describe_user_agent($s['ua']??'');$rows[]=['current'=>hash_equals($current,(string)$hash),'login'=>(int)($s['login']??0),'expires'=>(int)$s['expiration'],'device'=>trim(($description['platform']??'').' · '.($description['browser']??''),' ·')];}
        usort($rows,static function($a,$b){return ($b['current']<=>$a['current'])?:($b['login']<=>$a['login']);});return array_slice($rows,0,30);
    }
    public static function sessions_screen() {
        if(!is_user_logged_in())return;$uid=get_current_user_id();
        echo '<section class="dip-control-card"><h2>Sessions Web actives</h2><p>Contrôlez les navigateurs connectés. Si vous ne reconnaissez pas une connexion, déconnectez les autres sessions.</p>';
        foreach(self::session_rows($uid) as $row)echo '<article style="border-top:1px solid #8884;padding:12px 0"><strong>'.esc_html($row['device']?:'Navigateur').($row['current']?' — cet appareil':'').'</strong><p>Connexion : '.esc_html(wp_date('d/m/Y H:i',$row['login'])).'<br>Expiration : '.esc_html(wp_date('d/m/Y H:i',$row['expires'])).'</p></article>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dip_security_logout_others">';wp_nonce_field(DIP_Security_Center::NONCE);echo '<button class="button">Déconnecter les autres sessions Web et App</button></form></section>';
        echo DIP_App_Sync_V2::render_account_panel($uid);
    }
    public static function revocation_alert($uid,$reason) {
        if(DIP_Lockout::counter_hit('dip_revoke_notice_'.absint($uid),60)>1)return;
        if(class_exists('DIP_Email_Hub'))DIP_Email_Hub::send_user_security($uid,'Delicat — sessions révoquées','Les sessions de votre compte ont été révoquées. Si vous n’êtes pas à l’origine de cette action, contactez le support.');
    }
}
