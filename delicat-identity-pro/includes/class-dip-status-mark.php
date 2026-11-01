<?php
/**
 * 6.17.1: one status marker for admin checks and the customer security summary.
 * Until 6.16 these were emoji (green tick, red cross, warning sign); 6.17.0 removed them and left
 * some lists with no visible difference between a passing and a failing check. This draws a
 * Material icon of a distinct shape and colour per state, with the state in words for screen
 * readers, and falls back to the words alone when Delicat Builder's icon set is not available.
 */
if (!defined('ABSPATH')) exit;

final class DIP_Status_Mark {
    /** @var array<string,array{0:string,1:string,2:string}> state => [icon, colour, label] */
    private const STATES = [
        'ok'   => ['check-circle', '#15803D', 'OK'],
        'warn' => ['warning',      '#B45309', 'À vérifier'],
        'bad'  => ['error',        '#B91C1C', 'Échec'],
        'off'  => ['remove',       '#5F6B7E', 'Inactif'],
        'info' => ['lightbulb',    '#2563EB', 'Conseil'],
    ];

    /**
     * HTML for a state ('ok', 'warn', 'bad', 'off', 'info'); unknown states render as 'warn'.
     * $announce = false when the same line already says the state in words (no double reading).
     */
    public static function html(string $state, bool $announce = true): string {
        [$icon, $color, $label] = self::STATES[$state] ?? self::STATES['warn'];
        $glyph = function_exists('delicat_ds_icon') ? delicat_ds_icon($icon, 16) : '';
        if ($glyph === '') {
            return '<span class="dip-status-mark" style="color:' . $color . ';font-weight:700"' . ($announce ? '' : ' aria-hidden="true"') . '>'
                . esc_html($label) . '</span> ';
        }
        return '<span class="dip-status-mark" style="color:' . $color . ';display:inline-flex;vertical-align:-3px;margin-right:4px">'
            . $glyph . ($announce ? '<span class="screen-reader-text">' . esc_html($label) . '</span>' : '') . '</span>';
    }
}
