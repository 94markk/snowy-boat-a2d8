<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the supplier's identity out of everything a visitor, customer or
 * scanner can see: customer-facing messages, the public REST API index and
 * front-end asset URLs. Admin screens are unaffected.
 */
class FTR_Privacy
{
    /** Patterns that identify the supplier (name, domain, API host). */
    const SUPPLIER_PATTERN = '/(?:api\.)?flash[\s_\-]*top[\s_\-]*up(?:\.com)?/i';

    public static function init()
    {
        add_filter('rest_index', array(__CLASS__, 'filter_rest_index'), 99);
    }

    /** Name customers see in place of the supplier. */
    public static function public_name()
    {
        $s = ftr_settings();
        $name = isset($s['public_name']) ? trim((string) $s['public_name']) : '';
        if ($name === '') {
            $name = (string) get_bloginfo('name');
        }
        return $name !== '' ? $name : 'Store';
    }

    /**
     * Remove supplier references from text that may be shown to customers
     * (API error messages, check-ID results, timeline labels...).
     */
    public static function scrub($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return $text;
        }
        return trim((string) preg_replace(self::SUPPLIER_PATTERN, self::public_name(), $text));
    }

    /** True when the string mentions the supplier. */
    public static function mentions_supplier($text)
    {
        return (bool) preg_match(self::SUPPLIER_PATTERN, (string) $text);
    }

    /**
     * Hide the legacy supplier-named namespace from /wp-json/. The new private
     * webhook route is registered with show_in_index = false.
     */
    public static function filter_rest_index($response)
    {
        if (!$response instanceof WP_REST_Response) {
            return $response;
        }
        $data = $response->get_data();
        if (isset($data['namespaces']) && is_array($data['namespaces'])) {
            $data['namespaces'] = array_values(array_filter($data['namespaces'], function ($ns) {
                return !FTR_Privacy::mentions_supplier($ns) && $ns !== FTR_Webhook::ROUTE_NAMESPACE;
            }));
        }
        if (isset($data['routes']) && is_array($data['routes'])) {
            foreach (array_keys($data['routes']) as $route) {
                if (self::mentions_supplier($route) || strpos($route, '/' . FTR_Webhook::ROUTE_NAMESPACE) === 0) {
                    unset($data['routes'][$route]);
                }
            }
        }
        $response->set_data($data);
        return $response;
    }

    /**
     * Read a bundled asset for inline output, so front-end HTML never links
     * to the plugin folder. Block comments are stripped from CSS/JS.
     */
    public static function asset_contents($relative)
    {
        $file = FTR_DIR . 'assets/' . ltrim((string) $relative, '/');
        if (!is_readable($file)) {
            return '';
        }
        $code = (string) file_get_contents($file);
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        return trim((string) $code);
    }

    /** Enqueue a bundled stylesheet inline under a neutral handle. */
    public static function inline_style($handle, $relative)
    {
        if (wp_style_is($handle, 'enqueued')) {
            return;
        }
        wp_register_style($handle, false, array(), null);
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, self::asset_contents($relative));
    }

    /**
     * Enqueue a bundled script inline under a neutral handle, with its config
     * object printed just before it.
     */
    public static function inline_script($handle, $relative, $global, array $config)
    {
        if (wp_script_is($handle, 'enqueued')) {
            return;
        }
        wp_register_script($handle, false, array(), null, true);
        wp_enqueue_script($handle);
        wp_add_inline_script($handle, 'var ' . $global . ' = ' . wp_json_encode($config) . ';', 'before');
        wp_add_inline_script($handle, self::asset_contents($relative));
    }
}
