<?php
defined('ABSPATH') || exit;

/** Account ownership policy, client bans, safe avatars and separate password screen. */
final class DIP_Account_Control {
    const BAN = '_dip_signin_ban';
    const PHOTO = '_dip_avatar_attachment';
    const HIDE_PHOTO = '_dip_avatar_hidden';
    const ENDPOINT = 'change-password';
    const REVISION = '6.15.0';
    private static $photo_writer = 0;

    public static function init() {
        add_filter('wp_pre_insert_user_data', [__CLASS__, 'lock_email'], PHP_INT_MAX, 4);
        add_filter('rest_pre_insert_user', [__CLASS__, 'rest_email'], PHP_INT_MAX, 2);
        add_filter('update_user_metadata', [__CLASS__, 'lock_pending_email'], PHP_INT_MAX, 5);
        add_filter('add_user_metadata', [__CLASS__, 'lock_pending_email'], PHP_INT_MAX, 5);
        add_filter('delete_user_metadata', [__CLASS__, 'guard_meta'], PHP_INT_MAX, 5);
        add_action('user_profile_update_errors', [__CLASS__, 'profile_errors'], 50, 3);
        add_action('woocommerce_save_account_details_errors', [__CLASS__, 'woo_errors'], 100, 2);
        add_filter('authenticate', [__CLASS__, 'authenticate'], PHP_INT_MAX, 3);
        add_filter('determine_current_user', [__CLASS__, 'current_user'], PHP_INT_MAX);
        add_action('show_user_profile', [__CLASS__, 'admin_fields']);
        add_action('edit_user_profile', [__CLASS__, 'admin_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_admin']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_admin']);
        add_filter('manage_users_columns', [__CLASS__, 'user_columns']);
        add_filter('manage_users_custom_column', [__CLASS__, 'user_column'], 10, 3);
        add_action('init', [__CLASS__, 'endpoint']);
        add_action('init', [__CLASS__, 'upgrade'], 99);
        add_filter('woocommerce_get_query_vars', [__CLASS__, 'query_vars']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu'], 40);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'password_screen']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [__CLASS__, 'password_title']);
        add_filter('woocommerce_locate_template', [__CLASS__, 'profile_template'], 100, 3);
        add_action('woocommerce_before_edit_account_form', [__CLASS__, 'photo_form'], 5);
        add_action('admin_post_dip_profile_photo', [__CLASS__, 'save_photo']);
        add_action('admin_post_dip_change_password', [__CLASS__, 'save_password']);
        add_filter('dip_self_service_actions', [__CLASS__, 'self_actions']);
        add_filter('dip_sensitive_self_service_actions', [__CLASS__, 'sensitive_actions']);
        add_filter('get_avatar_data', [__CLASS__, 'avatar_data'], PHP_INT_MAX, 2);
        add_filter('dip_public_ajax_actions', [__CLASS__, 'public_ajax']);
        add_action('wp_ajax_nopriv_dip_native_request_magic', ['DIP_Passwordless_Registration', 'request_magic']);
        add_action('wp_ajax_dip_native_request_magic', ['DIP_Passwordless_Registration', 'request_magic']);
    }

    public static function self_actions($actions) { $actions[] = 'dip_profile_photo'; $actions[] = 'dip_change_password'; return $actions; }
    public static function sensitive_actions($actions) { $actions[] = 'dip_change_password'; return $actions; }
    public static function public_ajax($actions) { $actions[] = 'dip_native_request_magic'; return $actions; }
    public static function endpoint() { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); }
    public static function query_vars($vars) { $vars[self::ENDPOINT] = self::ENDPOINT; return $vars; }
    public static function menu($items) { if (is_user_logged_in()) $items[self::ENDPOINT] = self::password_title(); return $items; }
    public static function password_title($title = '') { return __('Mot de passe', 'delicat-google-login'); }
    public static function upgrade() {
        if (get_option('dip_account_control_revision') === self::REVISION) return;
        // Explicit owner request: enable magic links once, without resetting other choices.
        $settings = (array) get_option(DIP_Passwordless_Registration::OPTION, []);
        $settings['magic_enabled'] = 'yes';
        update_option(DIP_Passwordless_Registration::OPTION, $settings, false);
        $security=(array)get_option(DIP_Plugin::OPTION,[]);
        $security['new_device_email']='yes'; $security['trusted_devices_enabled']='yes';
        update_option(DIP_Plugin::OPTION,$security,false);
        flush_rewrite_rules(false);
        update_option('dip_account_control_revision', self::REVISION, true);
    }
    public static function admin_can_edit($uid) { return current_user_can('manage_options') && current_user_can('edit_user', absint($uid)); }
    public static function locked($uid) { return $uid && DIP_Account_Sync::is_customer_account($uid) && (!self::admin_can_edit($uid) || (class_exists('DIP_Email_Requests') && !DIP_Email_Requests::email_write_allowed($uid))); }
    public static function email_message() { return __('Pour modifier votre adresse e-mail, contactez le support. Seul un administrateur peut effectuer ce changement après vérification.', 'delicat-google-login'); }
    public static function lock_email($data, $update, $uid, $userdata = []) {
        if ($update && self::locked($uid)) {
            $user = get_userdata($uid);
            if ($user && isset($data['user_email'])) $data['user_email'] = $user->user_email;
        }
        return $data;
    }
    public static function rest_email($prepared, $request) {
        if (is_wp_error($prepared)) return $prepared;
        $uid = !empty($prepared->ID) ? absint($prepared->ID) : absint($request->get_param('id'));
        $user = $uid ? get_userdata($uid) : false;
        if ($user && self::locked($uid) && isset($prepared->user_email) && strtolower($prepared->user_email) !== strtolower($user->user_email)) return new WP_Error('dip_email_admin_only', self::email_message(), ['status'=>403]);
        return $prepared;
    }
    public static function lock_pending_email($check, $uid, $key, $value, $previous = null) {
        if (self::guard_meta($check,$uid,$key,$value,$previous) === false) return false;
        if ($key === '_new_email' && self::locked($uid)) return false;
        return $check;
    }
    /** Ban state and avatar pointers are never client-writable generic metadata. */
    public static function guard_meta($check,$uid,$key,$value=null,$previous=null) {
        if ($key===self::BAN && !self::admin_can_edit($uid)) return false;
        if (in_array($key,[self::PHOTO,self::HIDE_PHOTO],true) && !self::admin_can_edit($uid) && !(self::$photo_writer===absint($uid) && get_current_user_id()===absint($uid))) return false;
        return $check;
    }
    public static function profile_errors($errors, $update, $user) {
        if (!$update || empty($user->ID)) return;
        $old = get_userdata($user->ID);
        if ($old && self::locked($user->ID) && isset($user->user_email) && strtolower($user->user_email) !== strtolower($old->user_email)) $errors->add('dip_email_admin_only', self::email_message());
        if (self::admin_can_edit($user->ID) && isset($_POST['dip_ban_mode']) && self::valid_admin_nonce($user->ID)) {
            $ban = self::posted_ban();
            if (is_wp_error($ban)) $errors->add($ban->get_error_code(), $ban->get_error_message());
        }
    }
    public static function woo_errors($errors, $user) {
        $uid = get_current_user_id(); $old = get_userdata($uid);
        if ($old && self::locked($uid) && isset($user->user_email) && strtolower($user->user_email) !== strtolower($old->user_email)) $errors->add('dip_email_admin_only', self::email_message());
        foreach (['password_current','password_1','password_2'] as $key) {
            if (!empty($_POST[$key])) { $errors->add('dip_separate_password', __('Utilisez la rubrique « Mot de passe » pour modifier votre mot de passe.', 'delicat-google-login')); break; }
        }
    }

    /** The deadline is evaluated on each check; expiry never requires Cron. */
    public static function ban_error($uid) {
        $ban = get_user_meta(absint($uid), self::BAN, true);
        if (!is_array($ban)) return null;
        if (($ban['mode'] ?? '') === 'permanent') return new WP_Error('dip_account_banned', __('Ce compte est désactivé. Contactez le support.', 'delicat-google-login'), ['status'=>403]);
        if (($ban['mode'] ?? '') === 'until' && (int) ($ban['until'] ?? 0) > time()) return new WP_Error('dip_account_banned', sprintf(__('Ce compte est suspendu jusqu’au %s. Contactez le support si nécessaire.', 'delicat-google-login'), wp_date('d/m/Y H:i', (int)$ban['until'])), ['status'=>403]);
        return null;
    }
    public static function authenticate($user, $username = '', $password = '') { return $user instanceof WP_User ? (self::ban_error($user->ID) ?: $user) : $user; }
    public static function current_user($uid) { return $uid && self::ban_error($uid) ? 0 : $uid; }
    private static function posted($key) { return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : ''; }
    public static function posted_ban() {
        $mode = self::posted('dip_ban_mode');
        if (!in_array($mode, ['active','until','permanent'], true)) return new WP_Error('dip_ban_mode', __('État d’accès invalide.', 'delicat-google-login'));
        $until = 0;
        if ($mode === 'until') {
            $raw = self::posted('dip_ban_until');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$raw)) return new WP_Error('dip_ban_until', __('Choisissez une date de fin valide.', 'delicat-google-login'));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, wp_timezone());
            if (!$date || $date->format('Y-m-d\TH:i') !== $raw || $date->getTimestamp() <= time()) return new WP_Error('dip_ban_until', __('Choisissez une date de fin valide dans le futur.', 'delicat-google-login'));
            $until = $date->getTimestamp();
        }
        return ['mode'=>$mode, 'until'=>$until, 'reason'=>substr(sanitize_textarea_field(self::posted('dip_ban_reason')),0,500), 'actor'=>get_current_user_id(), 'changed'=>time()];
    }
    private static function valid_admin_nonce($uid) { return wp_verify_nonce(self::posted('dip_access_nonce'), 'dip_access_' . absint($uid)); }
    public static function admin_fields($user) {
        if (!self::admin_can_edit($user->ID) || !DIP_Account_Sync::is_customer_account($user->ID)) return;
        $ban = get_user_meta($user->ID, self::BAN, true); $ban = is_array($ban) ? $ban : [];
        $mode = is_wp_error(self::ban_error($user->ID)) ? ($ban['mode'] ?? 'active') : 'active';
        ?><h2><?php esc_html_e('Delicat — accès du client', 'delicat-google-login'); ?></h2>
        <?php wp_nonce_field('dip_access_' . $user->ID, 'dip_access_nonce'); ?>
        <table class="form-table"><tr><th><label for="dip-ban-mode">Accès au compte</label></th><td><select name="dip_ban_mode" id="dip-ban-mode"><option value="active" <?php selected($mode,'active'); ?>>Autorisé</option><option value="until" <?php selected($mode,'until'); ?>>Suspendu jusqu’à une date</option><option value="permanent" <?php selected($mode,'permanent'); ?>>Banni définitivement</option></select><p class="description">La suspension révoque immédiatement les sessions Web et App. Elle ne supprime ni les commandes ni le portefeuille.</p></td></tr>
        <tr><th><label for="dip-ban-until">Fin de suspension</label></th><td><input type="datetime-local" id="dip-ban-until" name="dip_ban_until" value="<?php echo esc_attr(!empty($ban['until']) ? wp_date('Y-m-d\TH:i',(int)$ban['until']) : ''); ?>"><p class="description">Fuseau du site : <?php echo esc_html(wp_timezone_string()); ?>. Requis seulement pour une suspension temporaire.</p></td></tr>
        <tr><th><label for="dip-ban-reason">Note privée</label></th><td><textarea name="dip_ban_reason" id="dip-ban-reason" rows="3" maxlength="500"><?php echo esc_textarea($ban['reason'] ?? ''); ?></textarea></td></tr></table><?php
    }
    public static function save_admin($uid) {
        $uid = absint($uid);
        if (!isset($_POST['dip_ban_mode']) || !self::admin_can_edit($uid) || $uid === get_current_user_id() || !DIP_Account_Sync::is_customer_account($uid) || !self::valid_admin_nonce($uid)) return;
        $ban = self::posted_ban(); if (is_wp_error($ban)) return;
        $old = get_user_meta($uid, self::BAN, true);
        if (!update_user_meta($uid, self::BAN, $ban) && get_user_meta($uid, self::BAN, true) !== $ban) return;
        if ($ban['mode'] !== 'active') self::revoke($uid, 'admin_ban');
        if (class_exists('DIP_Audit')) DIP_Audit::record('customer_access_changed', 'warning', $uid, ['mode'=>$ban['mode'],'until'=>$ban['until'],'actor'=>get_current_user_id()]);
        do_action('dip_customer_access_changed', $uid, $ban, $old);
    }
    private static function revoke($uid, $reason) {
        if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($uid)->destroy_all();
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($uid, $reason);
        // Notify integrations after the canonical browser/mobile stores are revoked.
        do_action('dip_customer_sessions_revoked', $uid, $reason);
    }
    public static function user_columns($columns) { if (current_user_can('manage_options')) $columns['dip_access'] = 'Accès Delicat'; return $columns; }
    public static function user_column($value, $column, $uid) {
        if ($column !== 'dip_access' || !current_user_can('manage_options')) return $value;
        return self::ban_error($uid) ? '<strong style="color:#b32d2e">Suspendu / banni</strong>' : 'Autorisé';
    }
    public static function profile_template($template, $name, $path) {
        if ($name === 'myaccount/form-edit-account.php' && is_user_logged_in()) return DIP_DIR . 'templates/account-profile.php';
        return $template;
    }
    private static function require_owner($nonce) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('Méthode non autorisée.', '', ['response'=>405]);
        if (!is_user_logged_in()) auth_redirect();
        $uid = get_current_user_id();
        if (!DIP_Access_Guard::can_self_service($uid) || self::ban_error($uid)) wp_die('Accès refusé.', '', ['response'=>403]);
        check_admin_referer($nonce);
        return $uid;
    }
    private static function profile_redirect($status) { wp_safe_redirect(add_query_arg('dip_profile_status', $status, wc_get_account_endpoint_url('edit-account'))); exit; }
    public static function password_screen() {
        if (!is_user_logged_in()) return;
        $uid = get_current_user_id();
        $managed = get_user_meta($uid, DIP_Identity::META_PASSWORD_MANAGED, true) === 'yes';
        ?><section class="dip-control-card"><h2>Modifier mon mot de passe</h2><p>Choisissez une phrase de passe d’au moins 12 caractères. Vous devrez ensuite vous reconnecter.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_change_password"><?php wp_nonce_field('dip_change_password'); ?>
        <?php if (!$managed): ?><label for="dip-current-password">Mot de passe actuel</label><input id="dip-current-password" name="current_password" type="password" autocomplete="current-password" required maxlength="1024"><?php endif; ?>
        <label for="dip-new-password">Nouveau mot de passe</label><input id="dip-new-password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required>
        <label for="dip-confirm-password">Confirmer le nouveau mot de passe</label><input id="dip-confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="256" required>
        <button type="submit" class="button">Enregistrer le mot de passe</button></form></section><?php
    }
    public static function password_valid($new, $confirm) {
        $length = function_exists('mb_strlen') ? mb_strlen($new,'UTF-8') : strlen($new);
        return $length >= 12 && $length <= 256 && strlen($new) <= 1024 && hash_equals($new, $confirm);
    }
    public static function save_password() {
        $uid = self::require_owner('dip_change_password');
        if (!DIP_Reauth::is_recent()) { wp_safe_redirect(DIP_Reauth::confirm_url(wc_get_account_endpoint_url(self::ENDPOINT), 'required')); exit; }
        $user = get_userdata($uid); $old = self::posted('current_password'); $new = self::posted('new_password'); $confirm = self::posted('confirm_password');
        if (!self::password_valid($new,$confirm)) { wc_add_notice('Utilisez 12 à 256 caractères et confirmez le même mot de passe.','error'); wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT)); exit; }
        $managed = get_user_meta($uid, DIP_Identity::META_PASSWORD_MANAGED, true) === 'yes';
        if (!$managed && (strlen($old)>4096 || !(wp_check_password($old,$user->user_pass,$uid) || (isset($_POST['current_password']) && is_string($_POST['current_password']) && strlen($_POST['current_password'])<=4096 && $_POST['current_password']!==$old && wp_check_password($_POST['current_password'],$user->user_pass,$uid))))) { wc_add_notice('Mot de passe actuel incorrect.','error'); wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT)); exit; }
        $result = wp_update_user(['ID'=>$uid,'user_pass'=>$new]);
        if (is_wp_error($result)) { wc_add_notice('Impossible de modifier votre mot de passe. Réessayez.','error'); wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT)); exit; }
        delete_user_meta($uid,DIP_Identity::META_PASSWORD_MANAGED);
        self::revoke($uid,'password_changed');
        if (class_exists('DIP_Audit')) DIP_Audit::record('customer_password_changed','notice',$uid);
        wp_logout(); wp_safe_redirect(add_query_arg('dip_identity_open','1',wc_get_page_permalink('myaccount')) . '#delicat-login'); exit;
    }

    public static function photo_form() {
        $uid = get_current_user_id(); if (!$uid) return;
        $status = isset($_GET['dip_profile_status']) && is_string($_GET['dip_profile_status']) ? $_GET['dip_profile_status'] : '';
        $messages = ['photo_saved'=>'Photo de profil mise à jour.','photo_removed'=>'Photo de profil supprimée.','photo_error'=>'Image refusée. Utilisez un fichier JPG, PNG ou WebP de 2 Mo maximum.','photo_busy'=>'Une mise à jour est déjà en cours. Réessayez.'];
        ?><section class="dip-control-card dip-photo-card"><h2>Photo de profil</h2><?php if (isset($messages[$status])): ?><p role="status"><?php echo esc_html($messages[$status]); ?></p><?php endif; ?>
        <img class="dip-profile-photo" src="<?php echo esc_url(get_avatar_url($uid,['size'=>128])); ?>" alt="Photo de profil" width="80" height="80">
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_profile_photo"><?php wp_nonce_field('dip_profile_photo'); ?>
        <label for="dip-photo-file">Choisir une photo</label><input type="file" name="profile_photo" id="dip-photo-file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG ou WebP · 2 Mo maximum. Les images sont redimensionnées et réencodées.</small>
        <div class="dip-photo-actions"><button class="button" type="submit" name="photo_action" value="upload">Enregistrer la photo</button><button class="button dip-photo-remove" type="submit" name="photo_action" value="remove">Supprimer la photo</button></div></form></section><?php
    }
    public static function validate_photo($file) {
        if (!is_array($file) || !isset($file['tmp_name'],$file['size'],$file['error'],$file['name']) || !is_string($file['tmp_name']) || !is_string($file['name']) || (int)$file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] <= 0 || (int)$file['size'] > 2097152 || !is_uploaded_file($file['tmp_name'])) return false;
        $type = wp_check_filetype_and_ext($file['tmp_name'],$file['name'],['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);
        $size = wp_getimagesize($file['tmp_name']);
        return in_array($type['type'] ?? '',['image/jpeg','image/png','image/webp'],true) && is_array($size) && in_array($size['mime'] ?? '',['image/jpeg','image/png','image/webp'],true) && $type['type']===$size['mime'] && $size[0]>=32 && $size[1]>=32 && $size[0]<=4096 && $size[1]<=4096 && $size[0]*$size[1]<=12000000;
    }
    private static function delete_owned_photo($id,$uid) {
        if ($id && (int)get_post_meta($id,'_dip_avatar_owner',true)===$uid && (int)get_post_field('post_author',$id)===$uid) wp_delete_attachment($id,true);
    }
    public static function save_photo() {
        $uid = self::require_owner('dip_profile_photo');
        $action = self::posted('photo_action');
        if (!in_array($action,['upload','remove'],true)) self::profile_redirect('photo_error');
        $saved = null; $temp = '';
        if ($action==='upload') {
            if (!self::validate_photo($_FILES['profile_photo'] ?? null)) self::profile_redirect('photo_error');
            $editor = wp_get_image_editor($_FILES['profile_photo']['tmp_name']);
            if (is_wp_error($editor)) self::profile_redirect('photo_error');
            if (method_exists($editor,'maybe_exif_rotate')) $editor->maybe_exif_rotate();
            if (is_wp_error($editor->resize(512,512,true))) self::profile_redirect('photo_error');
            $editor->set_quality(85); $temp = wp_tempnam('dip-avatar.jpg');
            if (!$temp) self::profile_redirect('photo_error');
            $saved = $editor->save($temp,'image/jpeg');
            if (is_wp_error($saved) || ($saved['mime-type'] ?? '')!=='image/jpeg') { if(is_array($saved) && !empty($saved['path'])) wp_delete_file($saved['path']); wp_delete_file($temp); self::profile_redirect('photo_error'); }
        }
        $lock='dip_avatar_' . $uid;
        if (!DIP_Lockout::mutex_acquire($lock,30,500)) { if ($saved) wp_delete_file($saved['path']); if ($temp) wp_delete_file($temp); self::profile_redirect('photo_busy'); }
        $status='photo_error';
        $previous_writer=self::$photo_writer;self::$photo_writer=$uid;
        try {
            $old=absint(get_user_meta($uid,self::PHOTO,true));
            if ($action==='remove') {
                $hidden=update_user_meta($uid,self::HIDE_PHOTO,'yes');
                if ($hidden || get_user_meta($uid,self::HIDE_PHOTO,true)==='yes') {
                    delete_user_meta($uid,self::PHOTO); self::delete_owned_photo($old,$uid); $status='photo_removed';
                }
            } else {
                $bytes=file_get_contents($saved['path']);
                $upload=is_string($bytes) ? wp_upload_bits('dip-avatar-' . wp_generate_password(20,false,false) . '.jpg',null,$bytes) : ['error'=>true];
                if (empty($upload['error'])) {
                    $id=wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>'Photo de profil','post_status'=>'inherit','post_author'=>$uid],$upload['file'],0,true);
                    if (is_wp_error($id)) wp_delete_file($upload['file']);
                    else {
                        $owned=update_post_meta($id,'_dip_avatar_owner',$uid);
                        if ($owned && update_user_meta($uid,self::PHOTO,$id)) { delete_user_meta($uid,self::HIDE_PHOTO); self::delete_owned_photo($old,$uid); $status='photo_saved'; }
                        else wp_delete_attachment($id,true);
                    }
                }
            }
        } finally {
            self::$photo_writer=$previous_writer;
            DIP_Lockout::mutex_release($lock);
            if ($saved) wp_delete_file($saved['path']); if ($temp) wp_delete_file($temp);
        }
        if ($status!=='photo_error' && class_exists('DIP_Audit')) DIP_Audit::record($status,'info',$uid);
        self::profile_redirect($status);
    }
    public static function avatar_data($args,$id) {
        if ($id instanceof WP_User) $uid=$id->ID;
        elseif (is_numeric($id)) $uid=absint($id);
        elseif (is_object($id) && isset($id->user_id)) $uid=absint($id->user_id);
        elseif (is_object($id) && isset($id->post_author)) $uid=absint($id->post_author);
        elseif (is_string($id) && is_email($id)) { $user=get_user_by('email',$id); $uid=$user ? $user->ID : 0; }
        else $uid=0;
        if (!$uid) return $args;
        if (get_user_meta($uid,self::HIDE_PHOTO,true)==='yes') { $args['url']=DIP_URL . 'assets/avatar-default.svg'; $args['found_avatar']=false; return $args; }
        $photo=absint(get_user_meta($uid,self::PHOTO,true));
        if ($photo && (int)get_post_meta($photo,'_dip_avatar_owner',true)===$uid && (int)get_post_field('post_author',$photo)===$uid) { $url=wp_get_attachment_url($photo); if ($url) { $args['url']=$url; $args['found_avatar']=true; } }
        return $args;
    }
}
