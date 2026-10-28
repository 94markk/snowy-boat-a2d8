<?php
defined('ABSPATH') || exit;

final class DIP_Integrity {
    public static function scan() {
        global $wpdb;
        $meta = DIP_Identity::META_SUB;
        $duplicates = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value google_sub, COUNT(*) total FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value HAVING COUNT(*) > 1",
            $meta
        ), ARRAY_A);
        $orphaned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE um.meta_key = %s AND u.ID IS NULL",
            $meta
        ));
        $privileged = 0;
        $linked_ids = get_users(['meta_key' => $meta, 'fields' => 'ID', 'number' => -1]);
        foreach ($linked_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user && (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || array_intersect((array) $user->roles, ['administrator','editor','shop_manager']))) $privileged++;
        }
        $result = [
            'checked_at' => time(),
            'linked_accounts' => count($linked_ids),
            'duplicate_identities' => count($duplicates),
            'orphaned_references' => $orphaned,
            'privileged_links' => $privileged,
            'status' => (!$duplicates && !$orphaned) ? 'healthy' : 'review',
        ];
        update_option('dip_last_integrity_scan', $result, false);
        DIP_Audit::record('integrity_scan', $result['status'] === 'healthy' ? 'info' : 'warning', get_current_user_id(), $result);
        return $result;
    }
}
