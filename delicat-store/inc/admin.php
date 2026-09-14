<?php
/**
 * Apparence › Delicat Store: a setup checklist with one-click actions.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function (): void {
		add_theme_page( 'Delicat Store', 'Delicat Store', 'edit_theme_options', 'delicat-store', 'ds_admin_screen' );
	}
);

/**
 * Checklist rows.
 *
 * @return array<int,array{ok:bool,label:string,help:string,url:string}>
 */
function ds_admin_checks(): array {
	$woo   = ds_is_woo();
	$rows  = array();
	$rows[] = array(
		'ok'    => $woo,
		'label' => 'WooCommerce est actif',
		'help'  => 'Le panier, le paiement et les commandes reposent sur WooCommerce.',
		'url'   => $woo ? '' : admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ),
	);
	$rows[] = array(
		'ok'    => $woo && 'HTG' === get_option( 'woocommerce_currency' ),
		'label' => 'Devise réglée sur la Gourde (HTG)',
		'help'  => 'Bouton « Régler pour Haïti » ci-dessous, ou WooCommerce › Réglages › Général.',
		'url'   => $woo ? admin_url( 'admin.php?page=wc-settings' ) : '',
	);
	$rows[] = array(
		'ok'    => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) > 0,
		'label' => 'Page d\'accueil statique définie',
		'help'  => 'Créée automatiquement à l\'activation. Bouton « Créer les pages » si elle manque.',
		'url'   => admin_url( 'options-reading.php' ),
	);
	$legal_missing = 0;
	foreach ( array_keys( ds_legal_documents() ) as $key ) {
		if ( ds_legal_page_id( $key ) <= 0 ) {
			++$legal_missing;
		}
	}
	$rows[] = array(
		'ok'    => 0 === $legal_missing,
		'label' => '10 pages légales installées' . ( $legal_missing > 0 ? " ({$legal_missing} manquante" . ( $legal_missing > 1 ? 's' : '' ) . ')' : '' ),
		'help'  => 'CGU, confidentialité, remboursement, mentions légales, Bon Kliyan…',
		'url'   => '',
	);
	$rows[] = array(
		'ok'    => '' !== trim( (string) ds_opt( 'legal_address' ) ) && '' !== trim( (string) ds_opt( 'legal_publisher' ) ),
		'label' => 'Informations légales renseignées',
		'help'  => 'Adresse, directeur de la publication, numéro fiscal : ils s\'insèrent dans les pages légales.',
		'url'   => admin_url( 'customize.php?autofocus[section]=ds_legal' ),
	);
	$rows[] = array(
		'ok'    => '' !== ds_whatsapp_number(),
		'label' => 'Numéro WhatsApp configuré',
		'help'  => 'Bouton de support, pied de page, page de remerciement.',
		'url'   => admin_url( 'customize.php?autofocus[section]=ds_identity' ),
	);
	$rows[] = array(
		'ok'    => has_custom_logo(),
		'label' => 'Logo ajouté',
		'help'  => 'Apparence › Personnaliser › Identité du site.',
		'url'   => admin_url( 'customize.php?autofocus[control]=custom_logo' ),
	);
	$rows[] = array(
		'ok'    => has_site_icon(),
		'label' => 'Icône du site (favicon et icône d\'application)',
		'help'  => 'Un carré de 512×512 px. Utilisé pour l\'écran d\'accueil quand un client installe la boutique.',
		'url'   => admin_url( 'customize.php?autofocus[control]=site_icon' ),
	);
	if ( $woo ) {
		$count  = wp_count_posts( 'product' );
		$n      = $count ? (int) $count->publish : 0;
		$rows[] = array(
			'ok'    => $n > 0,
			'label' => $n > 0 ? "{$n} produit(s) publié(s)" : 'Aucun produit publié',
			'help'  => 'Importez le catalogue d\'exemple pour tester, puis remplacez-le par vos vrais produits.',
			'url'   => admin_url( 'edit.php?post_type=product' ),
		);
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
		$rows[]   = array(
			'ok'    => array() !== $gateways,
			'label' => array() !== $gateways ? 'Moyen(s) de paiement actif(s) : ' . implode( ', ', array_map( static function ( $g ) { return $g->get_title(); }, $gateways ) ) : 'Aucun moyen de paiement actif',
			'help'  => 'MonCash (plugin dédié), carte via Stripe/PayPal, ou le Wallet (TeraWallet).',
			'url'   => admin_url( 'admin.php?page=wc-settings&tab=checkout' ),
		);
		$rows[] = array(
			'ok'    => function_exists( 'woo_wallet' ),
			'label' => function_exists( 'woo_wallet' ) ? 'Delicat Wallet (TeraWallet) actif' : 'Wallet non installé (facultatif)',
			'help'  => 'Plugin « TeraWallet » : solde client, recharge, paiement par solde. L\'onglet Wallet apparaît automatiquement.',
			'url'   => function_exists( 'woo_wallet' ) ? '' : admin_url( 'plugin-install.php?s=terawallet&tab=search&type=term' ),
		);
	}
	$rows[] = array(
		'ok'    => 0 === strpos( (string) get_locale(), 'fr' ),
		'label' => 'Langue du site en français',
		'help'  => 'Réglages › Général › Langue du site : « Français ». WooCommerce téléchargera sa traduction.',
		'url'   => admin_url( 'options-general.php' ),
	);
	return $rows;
}

/**
 * The screen.
 */
function ds_admin_screen(): void {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$notice = get_transient( 'ds_admin_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'ds_admin_notice_' . get_current_user_id() );
	}
	$action_url = admin_url( 'admin-post.php' );
	$button     = static function ( string $action, string $label, string $help, bool $danger = false ) use ( $action_url ): void {
		echo '<form method="post" action="' . esc_url( $action_url ) . '" class="ds-setup__action">';
		wp_nonce_field( 'ds_admin_' . $action );
		echo '<input type="hidden" name="action" value="ds_admin_action"><input type="hidden" name="ds_action" value="' . esc_attr( $action ) . '">';
		echo '<button class="button ' . ( $danger ? 'button-secondary' : 'button-primary' ) . '">' . esc_html( $label ) . '</button><span class="description">' . esc_html( $help ) . '</span></form>';
	};
	?>
	<div class="wrap ds-setup">
		<h1>Delicat Store — mise en route</h1>
		<?php if ( is_array( $notice ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $notice['text'] ); ?></p></div>
		<?php endif; ?>
		<div class="ds-setup__grid">
			<div class="ds-setup__card">
				<h2>Liste de contrôle</h2>
				<ul class="ds-setup__checks">
					<?php foreach ( ds_admin_checks() as $row ) : ?>
						<li class="<?php echo $row['ok'] ? 'is-ok' : 'is-todo'; ?>">
							<span class="ds-setup__mark" aria-hidden="true"><?php echo $row['ok'] ? '✓' : '○'; ?></span>
							<div>
								<strong><?php echo esc_html( $row['label'] ); ?></strong>
								<p><?php echo esc_html( $row['help'] ); ?>
								<?php if ( ! $row['ok'] && '' !== $row['url'] ) : ?>
									<a href="<?php echo esc_url( $row['url'] ); ?>">Régler →</a>
								<?php endif; ?></p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="ds-setup__card">
				<h2>Actions en un clic</h2>
				<?php
				$button( 'pages', 'Créer les pages manquantes', 'Accueil, Support, Comment ça marche, Bon Kliyan et les 10 pages légales. Les pages existantes ne sont pas modifiées.' );
				$button( 'menus', 'Créer les menus', 'Menu principal et menu de pied de page, seulement si aucun n\'est assigné.' );
				if ( ds_is_woo() ) {
					$button( 'haiti', 'Régler WooCommerce pour Haïti', 'Devise HTG, pays Haïti, livraison désactivée, commande sans compte, avis vérifiés.' );
					$button( 'classic', 'Panier / commande / compte en version classique', 'Remplace les blocs par les shortcodes WooCommerce, compatibles avec tous les plugins de paiement et entièrement stylés par le thème.' );
					$button( 'sample', 'Importer le catalogue d\'exemple', '16 produits numériques (Free Fire, Netflix, gift cards, MonCash, Digicel…) avec catégories, badges et champs Player ID. À supprimer avant l\'ouverture.', true );
				}
				?>
				<h2>Ensuite</h2>
				<ol class="ds-setup__next">
					<li><a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[panel]=ds_panel' ) ); ?>">Personnaliser</a> : couleurs, WhatsApp, textes de l'accueil, informations légales.</li>
					<li>Ajouter vos produits (WooCommerce › Produits). Sur chaque recharge, cochez « Champs client » et utilisez le préréglage <em>Player ID</em>.</li>
					<li>Ranger les produits avec les catégories <code>jeux</code>, <code>abonnement</code>, <code>echanges</code>, <code>gift-card</code>, <code>reseaux-sociaux</code>, <code>mobile</code> : les rayons de l'accueil et les badges les reconnaissent.</li>
					<li>Activer les paiements (WooCommerce › Réglages › Paiements) et faire une commande test.</li>
				</ol>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Action handler.
 */
add_action(
	'admin_post_ds_admin_action',
	static function (): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( 'Accès refusé.' );
		}
		$action = isset( $_POST['ds_action'] ) ? sanitize_key( (string) wp_unslash( $_POST['ds_action'] ) ) : '';
		check_admin_referer( 'ds_admin_' . $action );
		$text = '';
		$type = 'success';
		switch ( $action ) {
			case 'pages':
				$r    = ds_install_pages();
				$text = sprintf( 'Pages créées : %s. Déjà présentes : %s.', $r['created'] ? implode( ', ', $r['created'] ) : 'aucune', $r['existing'] ? count( $r['existing'] ) : '0' );
				break;
			case 'menus':
				$r    = ds_install_menus();
				$text = $r ? 'Menus créés : ' . implode( ', ', $r ) . '.' : 'Les emplacements de menu sont déjà assignés ; rien à faire.';
				break;
			case 'haiti':
				$r    = ds_install_haiti_defaults();
				$text = $r ? 'Réglages appliqués : ' . implode( ', ', $r ) . '.' : 'WooCommerce n\'est pas actif.';
				break;
			case 'classic':
				$r    = ds_install_classic_pages();
				$text = $r ? 'Pages passées en version classique : ' . implode( ', ', $r ) . '.' : 'Rien à changer : les pages utilisent déjà les shortcodes.';
				break;
			case 'sample':
				$r = ds_install_sample_products();
				if ( is_wp_error( $r ) ) {
					$type = 'error';
					$text = $r->get_error_message();
				} else {
					$text = sprintf( 'Catalogue d\'exemple : %d importé(s), %d mis à jour, %d échec(s). <a href="%s">Voir les produits</a>.', $r['imported'], $r['updated'], $r['failed'], esc_url( admin_url( 'edit.php?post_type=product' ) ) );
				}
				break;
			default:
				$type = 'error';
				$text = 'Action inconnue.';
		}
		set_transient( 'ds_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 60 );
		wp_safe_redirect( admin_url( 'themes.php?page=delicat-store' ) );
		exit;
	}
);

/**
 * A quiet reminder on the themes screen until the checklist is green.
 */
add_action(
	'admin_notices',
	static function (): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'themes', 'dashboard' ), true ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$todo = 0;
		foreach ( ds_admin_checks() as $row ) {
			if ( ! $row['ok'] ) {
				++$todo;
			}
		}
		if ( 0 === $todo ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Delicat Store</strong> : ' . (int) $todo . ' point(s) à régler avant l\'ouverture. <a href="' . esc_url( admin_url( 'themes.php?page=delicat-store' ) ) . '">Ouvrir la mise en route</a></p></div>';
	}
);
