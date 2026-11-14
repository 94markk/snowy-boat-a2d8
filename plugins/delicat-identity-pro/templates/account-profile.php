<?php
defined('ABSPATH') || exit;
$user = wp_get_current_user();
do_action('woocommerce_before_edit_account_form');
?>
<form class="woocommerce-EditAccountForm edit-account" action="" method="post">
  <?php do_action('woocommerce_edit_account_form_start'); ?>
  <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first"><label for="account_first_name">Prénom <span class="required">*</span></label><input type="text" class="woocommerce-Input input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr($user->first_name); ?>" required></p>
  <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last"><label for="account_last_name">Nom <span class="required">*</span></label><input type="text" class="woocommerce-Input input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr($user->last_name); ?>" required></p>
  <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="account_display_name">Nom affiché <span class="required">*</span></label><input type="text" class="woocommerce-Input input-text" name="account_display_name" id="account_display_name" value="<?php echo esc_attr($user->display_name); ?>" required></p>
  <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="account_email">Adresse e-mail</label><input type="email" class="woocommerce-Input input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr($user->user_email); ?>" readonly aria-describedby="dip-email-policy"><small id="dip-email-policy"><?php echo esc_html(DIP_Account_Control::email_message()); ?></small></p>
  <?php do_action('woocommerce_edit_account_form'); ?>
  <p><?php wp_nonce_field('save_account_details','save-account-details-nonce'); ?><input type="hidden" name="action" value="save_account_details"><button type="submit" class="woocommerce-Button button" name="save_account_details" value="Enregistrer">Enregistrer mes informations</button></p>
  <?php do_action('woocommerce_edit_account_form_end'); ?>
</form>
<?php do_action('woocommerce_after_edit_account_form'); ?>
