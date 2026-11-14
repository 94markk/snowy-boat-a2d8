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
        $linked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT um.user_id) FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE um.meta_key = %s",
            $meta
        ));
        // Only staff candidates (a staff role, or a direct manage_* grant in
        // the capabilities meta) are loaded and checked one by one: linked
        // customers are never loaded into memory on large stores.
        $roles = ['administrator', 'editor', 'shop_manager'];
        if (function_exists('wp_roles')) {
            foreach ((array) wp_roles()->roles as $role_name => $role) {
                $caps = (array) ($role['capabilities'] ?? []);
                if (!empty($caps['manage_options']) || !empty($caps['manage_woocommerce'])) $roles[] = (string) $role_name;
            }
        }
        $patterns = [];
        foreach (array_merge(array_unique($roles), ['manage_options', 'manage_woocommerce']) as $needle) $patterns[] = '%' . $wpdb->esc_like('"' . $needle . '"') . '%';
        $where = implode(' OR ', array_fill(0, count($patterns), 'cap.meta_value LIKE %s'));
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT um.user_id FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = um.user_id AND cap.meta_key = %s WHERE um.meta_key = %s AND ({$where}) LIMIT 1000",
            array_merge([$wpdb->get_blog_prefix() . 'capabilities', $meta], $patterns)
        ));
        $privileged = 0;
        foreach ((array) $candidates as $user_id) {
            $user = get_userdata((int) $user_id);
            if ($user && (user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || array_intersect((array) $user->roles, ['administrator','editor','shop_manager']))) $privileged++;
        }
        $result = [
            'checked_at' => time(),
            'linked_accounts' => $linked,
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
