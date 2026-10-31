<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Email {

    private static function admin_email(): string {
        return get_option( 'delicat_admin_email', get_option( 'admin_email' ) );
    }

    private static function site_name(): string {
        return get_option( 'blogname', 'Delicat Store Haiti' );
    }

    private static function headers(): array {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::site_name() . ' <' . self::admin_email() . '>',
        ];
    }

    // ── Admin: new request received ──────────────────────────────────────

    public static function send_admin_notification( object $r, string $label = '' ): void {
        $subject = sprintf( '[%s] Recharge #%d — %.2f HTG via %s %s',
            self::site_name(), $r->id, $r->montant, strtoupper( $r->tab ), $label );

        $preuve = $r->preuve_url
            ? '<a href="' . esc_url( Delicat_Files::view_url( $r->preuve_url ) ) . '">Voir la preuve (admin)</a>'
            : '—';

        $body = self::wrap( "
            <h2>Nouvelle demande de recharge {$label}</h2>
            " . self::table( [
                'ID'           => '#' . $r->id,
                'Email'        => esc_html( $r->user_email ),
                'Tab / Méthode'=> esc_html( strtoupper( $r->tab ) . ' / ' . $r->methode ),
                'Montant'      => number_format( $r->montant, 2 ) . ' HTG',
                'TransCode'    => esc_html( $r->trans_code ?: '—' ),
                'Preuve'       => $preuve,
                'Statut'       => self::badge( $r->statut ),
                'Note'         => esc_html( $r->note_admin ?: '—' ),
                'Date'         => esc_html( $r->created_at ),
            ] ) . "
            <p style='margin-top:20px;'>
                <a href='" . admin_url( 'admin.php?page=delicat-recharges' ) . "'
                   style='background:#1a1a2e;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:bold;'>
                    Gérer les demandes →
                </a>
            </p>
        " );

        wp_mail( self::admin_email(), $subject, $body, self::headers() );
    }

    // ── User: confirmation (pending) ─────────────────────────────────────

    public static function send_user_confirmation( object $r ): void {
        if ( ! $r->user_email ) return;

        $subject = sprintf( '[%s] Demande #%d reçue – en attente de traitement', self::site_name(), $r->id );
        $body = self::wrap( "
            <h2>Demande de recharge reçue !</h2>
            <p>Bonjour,</p>
            <p>Nous avons bien reçu votre demande de recharge de <strong>" . number_format( $r->montant, 2 ) . " HTG</strong>
               via <strong>" . esc_html( strtoupper( $r->tab ) ) . "</strong>.</p>
            <p>Numéro de demande : <strong>#" . $r->id . "</strong><br>
               Statut actuel : <strong>En attente de vérification</strong></p>
            <p>Vous recevrez un email dès que votre wallet sera rechargé.</p>
            " . self::footer_note() . "
        " );

        wp_mail( $r->user_email, $subject, $body, self::headers() );
    }

    // ── User: auto-approved (MonCash/Natcash instant credit) ─────────────

    public static function send_user_auto_approved( object $r, bool $wallet_ok ): void {
        if ( ! $r->user_email ) return;

        $wallet_msg = $wallet_ok
            ? '<p style="color:#2e7d32;font-weight:bold;">✅ ' . number_format( $r->montant, 2 ) . ' HTG ont été ajoutés à votre wallet Delicat Store.</p>'
            : '<p style="color:#e65100;">⚠️ Le crédit wallet a échoué automatiquement. Notre équipe le fera manuellement sous peu.</p>';

        $subject = sprintf( '[%s] ✅ Wallet rechargé — %.2f HTG', self::site_name(), $r->montant );
        $body = self::wrap( "
            <h2>Recharge confirmée !</h2>
            <p>Bonjour,</p>
            {$wallet_msg}
            " . self::table( [
                'Demande' => '#' . $r->id,
                'Méthode' => esc_html( strtoupper( $r->tab ) ),
                'Montant' => number_format( $r->montant, 2 ) . ' HTG',
            ] ) . "
            <p style='margin-top:16px;'>
                <a href='" . home_url( '/my-wallet/' ) . "'
                   style='background:#43a047;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:bold;'>
                    Voir mon wallet →
                </a>
            </p>
            " . self::footer_note() . "
        " );

        wp_mail( $r->user_email, $subject, $body, self::headers() );
    }

    // ── User: admin approved or rejected ────────────────────────────────

    public static function send_status_update( object $r ): void {
        if ( ! $r->user_email ) return;

        if ( $r->statut === 'approuve' ) {
            $subject = sprintf( '[%s] ✅ Wallet rechargé — %.2f HTG', self::site_name(), $r->montant );
            $content = "<p>Bonne nouvelle ! Votre demande <strong>#" . $r->id . "</strong> a été <span style='color:#2e7d32;font-weight:bold;'>approuvée</span>.</p>
                        <p><strong>" . number_format( $r->montant, 2 ) . " HTG</strong> ont été ajoutés à votre wallet.</p>
                        <p><a href='" . home_url( '/my-wallet/' ) . "'
                              style='background:#43a047;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block;margin-top:8px;'>
                            Voir mon wallet →
                        </a></p>";
        } elseif ( $r->statut === 'rejete' ) {
            $subject = sprintf( '[%s] ❌ Demande #%d rejetée', self::site_name(), $r->id );
            $content = "<p>Malheureusement, votre demande <strong>#" . $r->id . "</strong> de <strong>" . number_format( $r->montant, 2 ) . " HTG</strong>
                        a été <span style='color:#c62828;font-weight:bold;'>rejetée</span>.</p>
                        <p>Si vous pensez qu'il s'agit d'une erreur, contactez notre support sur WhatsApp.</p>";
        } else {
            return;
        }

        $body = self::wrap( "<h2>Mise à jour de votre demande</h2>{$content}" . self::footer_note() );
        wp_mail( $r->user_email, $subject, $body, self::headers() );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function table( array $rows ): string {
        $html = "<table style='width:100%;border-collapse:collapse;margin-top:12px;'>";
        foreach ( $rows as $label => $value ) {
            $html .= "<tr>
                <td style='padding:8px 12px;border:1px solid #e0e0e0;background:#f5f5f5;font-weight:600;width:35%;'>{$label}</td>
                <td style='padding:8px 12px;border:1px solid #e0e0e0;'>{$value}</td>
              </tr>";
        }
        return $html . '</table>';
    }

    private static function badge( string $statut ): string {
        $map = [
            'en_attente' => [ '#f0ad4e', '⏳ En attente' ],
            'approuve'   => [ '#5cb85c', '✅ Approuvé' ],
            'rejete'     => [ '#d9534f', '❌ Rejeté' ],
        ];
        [ $color, $label ] = $map[ $statut ] ?? [ '#999', $statut ];
        return "<span style='background:{$color};color:#fff;padding:3px 10px;border-radius:10px;font-size:12px;'>{$label}</span>";
    }

    private static function footer_note(): string {
        return "<hr style='border:none;border-top:1px solid #eee;margin:20px 0;'/>
                <p style='font-size:11px;color:#aaa;'>Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.<br>
                © " . date('Y') . ' ' . esc_html( self::site_name() ) . ". Tous droits réservés.</p>";
    }

    private static function wrap( string $content ): string {
        $name = esc_html( self::site_name() );
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
        <body style='font-family:Arial,sans-serif;max-width:620px;margin:0 auto;padding:20px;color:#333;background:#f9f9f9;'>
            <div style='background:#1a1a2e;padding:22px 28px;border-radius:10px 10px 0 0;'>
                <h1 style='color:#fff;margin:0;font-size:20px;letter-spacing:1px;'>{$name}</h1>
            </div>
            <div style='background:#fff;padding:28px 28px 20px;border:1px solid #e8e8e8;border-radius:0 0 10px 10px;'>
                {$content}
            </div>
        </body></html>";
    }
}
