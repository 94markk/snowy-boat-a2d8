<?php
defined('ABSPATH') || exit;

/** Only this reviewed support workflow can change an existing customer's email. */
final class DIP_Email_Requests {
    const META='_dip_email_change_request';
    private static $writer=0;
    private static $approved=0;
    public static function init() {
        add_action('admin_post_dip_email_request',[__CLASS__,'request']);
        add_action('admin_post_dip_email_approve',[__CLASS__,'approve']);
        add_filter('dip_admin_only_actions',static function($a){return array_merge($a,['dip_email_request','dip_email_approve']);});
        foreach(['add','update','delete'] as $op)add_filter($op.'_user_metadata',[__CLASS__,'protect'],PHP_INT_MAX,5);
    }
    public static function protect($check,$uid,$key,$value=null,$previous=null) {
        if($key===self::META && (!self::$writer || self::$writer!==absint($uid)))return false;
        return $check;
    }
    private static function save($uid,array $record) {
        $cipher=DIP_Crypto::encrypt(wp_json_encode($record));
        if(!$cipher)return false;
        self::$writer=absint($uid);
        try{return update_user_meta($uid,self::META,$cipher)!==false;}finally{self::$writer=0;}
    }
    public static function record($uid) {
        $raw=get_user_meta($uid,self::META,true);
        $data=is_string($raw)&&strpos($raw,DIP_Crypto::PREFIX)===0?json_decode(DIP_Crypto::decrypt($raw),true):null;
        return is_array($data)?$data:[];
    }
    public static function email_write_allowed($uid) {
        return self::$approved===absint($uid) && current_user_can('manage_options') && current_user_can('edit_user',absint($uid)) && DIP_Two_Factor::is_enabled() && DIP_Reauth::is_recent();
    }
    private static function post($key) {return isset($_POST[$key])&&is_scalar($_POST[$key])?trim((string)wp_unslash($_POST[$key])):'';}
    private static function failure($message) {wp_die(esc_html($message),'',['response'=>400]);}
    public static function request() {
        DIP_Protection::require_admin('dip_email_request');
        $uid=absint(self::post('client_id'));$email=sanitize_email(self::post('new_email'));$reason=substr(sanitize_textarea_field(self::post('review_note')),0,1000);$user=get_userdata($uid);
        if(!$user||!DIP_Account_Sync::is_customer_account($uid)||!current_user_can('edit_user',$uid)||!is_email($email)||strtolower($email)===strtolower($user->user_email)||email_exists($email)||strlen($reason)<10||self::post('identity_checked')!=='1')self::failure('Vérifiez le client, la nouvelle adresse disponible et la justification de la vérification d’identité.');
        if(DIP_Lockout::counter_hit('dip_email_request_'.$uid,3600)>3)self::failure('Trop de demandes pour ce client. Réessayez dans une heure.');
        $code=(string)random_int(100000,999999);
        $r=['id'=>bin2hex(random_bytes(16)),'uid'=>$uid,'old_hash'=>hash('sha256',strtolower($user->user_email)),'new'=>$email,'code'=>wp_hash_password($code),'actor'=>get_current_user_id(),'created'=>time(),'expires'=>time()+86400,'state'=>'pending','reason'=>$reason];
        if(!self::save($uid,$r))self::failure('Impossible de sauvegarder la demande.');
        $sent=wp_mail($email,'Delicat — vérification de votre nouvelle adresse','Votre code de vérification est '.$code.'. Il expire dans 24 heures. Communiquez-le uniquement au support Delicat pour votre demande de changement d’adresse. Ce code ne connecte pas votre compte. Si vous n’avez rien demandé, ignorez ce message.');
        $old_sent=wp_mail($user->user_email,'Delicat — demande de changement d’adresse','Le support a ouvert une demande de changement de votre adresse e-mail. Votre adresse actuelle n’a pas encore changé. Si vous n’avez pas demandé cette opération, contactez immédiatement le support Delicat.');
        DIP_Audit::record('email_change_requested','warning',$uid,['request'=>$r['id'],'admin'=>get_current_user_id(),'new_mail_accepted'=>(bool)$sent,'old_notice_accepted'=>(bool)$old_sent]);
        if(!$sent){$r['state']='delivery_failed';unset($r['code']);self::save($uid,$r);self::failure('Le transport a refusé le message de vérification. Corrigez l’envoi avant de recommencer.');}
        wp_safe_redirect(DIP_Protection::url('email_requested'));exit;
    }
    public static function valid_request(array $r,$uid,$id,$old_email) {
        return ($r['state']??'')==='pending' && (int)($r['uid']??0)===(int)$uid && (int)($r['expires']??0)>time() && isset($r['id'],$r['old_hash']) && hash_equals($r['id'],$id) && hash_equals($r['old_hash'],hash('sha256',strtolower($old_email)));
    }
    public static function approve() {
        DIP_Protection::require_admin('dip_email_approve');
        $uid=absint(self::post('client_id'));$user=get_userdata($uid);$id=self::post('request_id');$note=substr(sanitize_textarea_field(self::post('approval_note')),0,1000);
        if(!$user||!current_user_can('edit_user',$uid)||self::post('identity_checked')!=='1'||strlen($note)<10)self::failure('Vérification d’identité et compte rendu requis.');
        $r=self::record($uid);
        if(!self::valid_request($r,$uid,$id,$user->user_email))self::failure('Demande expirée, déjà traitée ou remplacée.');
        if(DIP_Lockout::counter_hit('dip_email_approve_'.hash('sha256',$id),86400)>5)self::failure('Trop de tentatives. Créez une nouvelle demande.');
        $code=self::post('verification_code');
        if(!preg_match('/^\d{6}$/D',$code)||!wp_check_password($code,$r['code'])||email_exists($r['new']))self::failure('Code incorrect ou adresse indisponible.');
        // Atomic one-time claim. Concurrent administrators cannot approve the same request.
        $claim='dip_tx_used_'.hash('sha256','email|'.$id).'_'.$r['expires'];
        if(!add_option($claim,1,'',false))self::failure('Cette demande a déjà été traitée.');
        $old=$user->user_email;self::$approved=$uid;
        try{$result=wp_update_user(['ID'=>$uid,'user_email'=>$r['new']]);}finally{self::$approved=0;}
        if(is_wp_error($result)){delete_option($claim);self::failure('Changement refusé par WordPress. Vérifiez le compte avant de recommencer.');}
        $changed=get_userdata($uid);
        if(!$changed||strtolower($changed->user_email)!==strtolower($r['new']))self::failure('Le changement a été bloqué. Contrôlez les règles du site.');
        $r['state']='approved';$r['approved_by']=get_current_user_id();$r['approved_at']=time();$r['approval_note']=$note;unset($r['code']);self::save($uid,$r);
        if(class_exists('WP_Session_Tokens'))WP_Session_Tokens::get_instance($uid)->destroy_all();
        if(class_exists('DIP_Mobile_API'))DIP_Mobile_API::revoke_all_for_user($uid,'admin_email_change');
        $notice=wp_mail($old,'Delicat — votre adresse e-mail a été modifiée','Un administrateur a validé votre demande de changement d’adresse après vérification. Les sessions ont été révoquées. Si vous n’avez pas autorisé cette opération, contactez immédiatement le support Delicat.');
        DIP_Audit::record('email_change_approved','critical',$uid,['request'=>$id,'admin'=>get_current_user_id(),'old_notice_accepted'=>(bool)$notice]);
        wp_safe_redirect(DIP_Protection::url('email_approved'));exit;
    }
    public static function admin_list() {
        echo '<h2>Demandes de changement d’e-mail</h2><p>Les clients contactent le support. Documentez une vérification d’identité indépendante de la nouvelle adresse. Le code prouve uniquement la possession de cette adresse. Aucun changement direct depuis le profil ou l’API n’est autorisé.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('dip_email_request');
        echo '<input type="hidden" name="action" value="dip_email_request"><p><label>ID client <input type="number" name="client_id" min="1" required></label> <label>Nouvelle adresse <input type="email" name="new_email" required></label></p><p><label><input type="checkbox" name="identity_checked" value="1" required> Identité du client vérifiée par le support</label></p><p><label>Méthode et référence du dossier (sans document sensible) <textarea name="review_note" minlength="10" maxlength="1000" required></textarea></label></p><button class="button">Créer la demande et envoyer le code</button></form>';
        // Bounded list; individual records are encrypted and never exposed by REST metadata.
        $page=max(1,absint($_GET['dip_email_page']??1));
        $users=get_users(['meta_key'=>self::META,'number'=>20,'offset'=>($page-1)*20,'orderby'=>'ID','order'=>'DESC']);
        foreach($users as $user){$r=self::record($user->ID);if(!$r)continue;
            echo '<article style="border-top:1px solid #ccc;padding:15px 0"><h3>Client #'.absint($user->ID).' — '.esc_html($r['state']).'</h3><p>'.esc_html($r['new']).' · '.esc_html(wp_date('d/m/Y H:i',$r['created'])).'</p><p>Demande par #'.absint($r['actor']).' : '.esc_html($r['reason']).'</p>';
            if(($r['state']??'')==='pending'&&$r['expires']>time()){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('dip_email_approve');echo '<input type="hidden" name="action" value="dip_email_approve"><input type="hidden" name="client_id" value="'.absint($user->ID).'"><input type="hidden" name="request_id" value="'.esc_attr($r['id']).'"><label>Code reçu à la nouvelle adresse <input name="verification_code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" required></label><p><label><input type="checkbox" name="identity_checked" value="1" required> Identité et dossier revérifiés avant validation</label></p><p><textarea name="approval_note" placeholder="Compte rendu de validation" minlength="10" maxlength="1000" required></textarea></p><button class="button button-primary">Valider le changement et révoquer les sessions</button></form>';}
            elseif(isset($r['approved_by']))echo '<p>Validée par #'.absint($r['approved_by']).' : '.esc_html($r['approval_note']).'</p>';echo '</article>';
        }
        if($page>1)echo '<a href="'.esc_url(add_query_arg('dip_email_page',$page-1,DIP_Protection::url())).'">Page précédente</a> ';
        if(count($users)===20)echo '<a href="'.esc_url(add_query_arg('dip_email_page',$page+1,DIP_Protection::url())).'">Page suivante</a>';
    }
}
