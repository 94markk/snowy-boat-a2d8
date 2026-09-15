<?php
defined( 'ABSPATH' ) || exit;

/** Native support and legal information pages. */
final class Delicat_Builder_V9_Native_Pages {
	private const OPTION = 'delicat_builder_v9_native_pages';
	private const META = '_delicat_builder_v9_native_page';

	public static function boot(): void {
		add_shortcode( 'delicat_native_support', array( __CLASS__, 'support' ) );
		add_shortcode( 'delicat_native_terms', array( __CLASS__, 'terms' ) );
		add_shortcode( 'delicat_native_privacy', array( __CLASS__, 'privacy' ) );
		add_shortcode( 'delicat_v8_hero', array( __CLASS__, 'legacy_v8_hero' ) );
		/* RC81.2: these two shortcodes rendered legal text hardcoded in this file,
		 * so a page whose body is just [delicat_native_terms] kept showing the old
		 * wording no matter what was written into the page. That is why the new
		 * documents appeared to be ignored. Legal now owns the text; this file
		 * keeps only the page chrome. */
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99995 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 40 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_cache_policy' ), 4 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ), 24 );
	}

	private static function type(): string {
		if ( ! is_singular( 'page' ) ) {
			return '';
		}
		return sanitize_key( (string) get_post_meta( get_queried_object_id(), self::META, true ) );
	}

	public static function template( $template ) {
		if ( ! is_string( $template ) || ! in_array( self::type(), array( 'support', 'terms', 'privacy' ), true ) ) {
			return $template;
		}
		$native = DELICAT_BUILDER_V9_DIR . 'templates/native-content-page.php';
		return is_readable( $native ) ? $native : $template;
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		$type = self::type();
		if ( $type ) {
			$classes[] = 'delicat-native-info-page';
			$classes[] = 'delicat-native-info-' . sanitize_html_class( $type );
		}
		return array_values( array_unique( $classes ) );
	}

	public static function enqueue(): void {
		$type = self::type();
		$post = is_singular( 'page' ) ? get_post( get_queried_object_id() ) : null;
		$legacy_hero = $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'delicat_v8_hero' );
		if ( ! $type && ! $legacy_hero ) {
			return;
		}
		$path = DELICAT_BUILDER_V9_DIR . 'assets/css/native-pages.css';
		if ( is_file( $path ) ) {
			wp_enqueue_style( 'delicat-builder-v9-native-pages', DELICAT_BUILDER_V9_URL . 'assets/css/native-pages.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
	}

	public static function prepare_cache_policy(): void {
		if ( ! self::type() || ! class_exists( 'Delicat_Builder_V9_Security', false ) ) {
			return;
		}
		$performance = class_exists( 'Delicat_Builder_V9_Performance', false ) && is_callable( array( 'Delicat_Builder_V9_Performance', 'settings' ) )
			? Delicat_Builder_V9_Performance::settings()
			: array();
		if ( empty( $performance['server_page_cache_hint'] ) ) { return; }
		if ( Delicat_Builder_V9_Security::public_cache_allowed() ) {
			do_action( 'litespeed_control_set_cacheable', 'Delicat V9 native information page' );
			return;
		}
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) && Delicat_Builder_V9_Security::private_cache_allowed() ) { Delicat_Builder_V9_Security::hint_private_cache( 'Delicat V9 information page (signed-in)' ); return; } /* RC32 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'Delicat V9 private/session information page' );
	}

	public static function maybe_install(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPTION . '_version', '' ) === DELICAT_BUILDER_V9_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $cli && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$definitions = array(
			'support' => array( 'title' => 'Support Delicat', 'slug' => 'support', 'shortcode' => '[delicat_native_support]' ),
			/* RC81.2: terms and privacy are provisioned by Delicat_Builder_V9_Legal,
			 * which writes the document text into the page itself. Re-seeding the
			 * shortcode here would overwrite it on every install pass. */
		);
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( $definitions as $type => $definition ) {
			$page_id = absint( $stored[ $type ] ?? 0 );
			$page = $page_id ? get_post( $page_id ) : null;
			$seed_empty_page = false;
			if ( ! $page instanceof WP_Post ) {
				$existing = get_page_by_path( $definition['slug'], OBJECT, 'page' );
				if ( $existing instanceof WP_Post && ( '' === trim( wp_strip_all_tags( $existing->post_content ) ) || has_shortcode( $existing->post_content, 'delicat_native_' . $type ) ) ) {
					$page_id = $existing->ID;
					$seed_empty_page = '' === trim( wp_strip_all_tags( $existing->post_content ) );
				} else {
					$page_id = wp_insert_post(
						array(
							'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $definition['title'],
							'post_name' => $existing ? $definition['slug'] . '-delicat' : $definition['slug'],
							'post_content' => $definition['shortcode'], 'comment_status' => 'closed',
						),
						true
					);
					if ( is_wp_error( $page_id ) ) {
						continue;
					}
				}
			}
			$page_id = absint( $page_id );
			if ( $page_id ) {
				// Never overwrite edited legal/support copy during a plugin upgrade.
				// An adopted empty page may receive the shortcode, but its existing
				// draft/publish status remains under the site owner's control.
				if ( $seed_empty_page ) {
					wp_update_post( array( 'ID' => $page_id, 'post_content' => $definition['shortcode'] ) );
				}
				update_post_meta( $page_id, self::META, $type );
				$stored[ $type ] = $page_id;
			}
		}
		update_option( self::OPTION, $stored, false );
		update_option( self::OPTION . '_version', DELICAT_BUILDER_V9_VERSION, false );
		if ( empty( get_option( 'wp_page_for_privacy_policy' ) ) && ! empty( $stored['privacy'] ) ) {
			update_option( 'wp_page_for_privacy_policy', absint( $stored['privacy'] ) );
		}
	}

	private static function context(): array {
		$email = sanitize_email( (string) get_option( 'delicat_builder_v9_support_email', 'support@delicastoreha.com' ) );
		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		$parts = array_map( 'absint', explode( ' ', wp_date( 'j n Y' ) ) );
		$months = array( 1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre' );
		$updated = count( $parts ) === 3 && isset( $months[ $parts[1] ] )
			? $parts[0] . ' ' . $months[ $parts[1] ] . ' ' . $parts[2]
			: wp_date( 'j/m/Y' );
		return array(
			'name' => wp_strip_all_tags( get_bloginfo( 'name' ) ?: 'Delicat Store Haiti' ),
			'email' => $email,
			'url' => home_url( '/' ),
			'updated' => $updated,
		);
	}

	private static function hero( string $eyebrow, string $title, string $text, string $icon ): string {
		return '<header class="dni-hero"><div><span class="dni-eyebrow">' . esc_html( $eyebrow ) . '</span><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></div><span class="dni-hero-icon" aria-hidden="true">' . esc_html( $icon ) . '</span></header>';
	}

	private static function section( string $id, string $title, string $body ): string {
		return '<section id="' . esc_attr( $id ) . '" class="dni-section"><h2>' . esc_html( $title ) . '</h2>' . $body . '</section>';
	}

	/**
	 * Backward-compatible replacement for the retired V8 hero shortcode still
	 * stored on older landing pages (notably /sondaj/). It intentionally renders
	 * presentation only; the survey/competition engine remains owned by the page.
	 */
	public static function legacy_v8_hero(): string {
		$title = is_singular( 'page' ) ? wp_strip_all_tags( get_the_title() ) : get_bloginfo( 'name' );
		$slug = is_singular( 'page' ) ? sanitize_title( (string) get_post_field( 'post_name', get_queried_object_id() ) ) : '';
		$eyebrow = 'DELICAT STORE';
		$text = 'Recharge rapide, simple et sécurisée.';
		if ( 'sondaj' === $slug ) {
			$title = 'Sondaj & Compétition Free Fire';
			$eyebrow = 'COMMUNAUTÉ';
			$text = 'Participez au sondage et consultez les activités disponibles.';
		}
		return '<header class="dni-hero dbv9-legacy-hero"><div><span class="dni-eyebrow">' . esc_html( $eyebrow ) . '</span><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></div></header>';
	}

	public static function support(): string {
		$c = self::context();
		$email_link = '<a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a>';
		$out = '<div class="dni-page dni-support">' . self::hero( 'AIDE & CONTACT', 'Comment pouvons-nous vous aider ?', 'Des réponses claires pour vos commandes, paiements, recharges, cartes cadeaux et abonnements numériques.', '◎' );
		$out .= '<div class="dni-action-grid"><a href="mailto:' . esc_attr( $c['email'] ) . '"><b>✉ Écrire au support</b><span>' . esc_html( $c['email'] ) . '</span></a><a href="' . esc_url( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/my-account/orders/' ) ) . '"><b>▣ Suivre une commande</b><span>État et détails de livraison</span></a><a href="#faq"><b>? Questions fréquentes</b><span>Réponses immédiates</span></a></div>';
		$out .= self::section( 'avant-contact', 'Avant de nous contacter', '<ol><li>Vérifiez l’adresse e-mail utilisée et votre dossier indésirable.</li><li>Ouvrez <strong>Mon compte → Commandes</strong> pour consulter le statut et les notes.</li><li>Préparez votre numéro de commande. Ne transmettez jamais au support votre mot de passe, code bancaire ou code à usage unique. Si une fiche produit demande exceptionnellement une information sensible de compte pour la livraison, saisissez-la uniquement dans le champ sécurisé de cette fiche.</li></ol>' );
		$out .= self::section( 'statuts', 'Comprendre le statut de votre commande', '<div class="dni-status-grid"><article><b>En attente de paiement</b><p>Le paiement n’a pas encore été confirmé.</p></article><article><b>En cours</b><p>La commande est validée et la livraison numérique est en traitement.</p></article><article><b>Terminée</b><p>Le fournisseur a confirmé la livraison. Consultez les détails de commande et votre e-mail.</p></article><article><b>Échouée ou remboursée</b><p>La transaction n’a pas abouti ou un remboursement a été engagé selon le moyen de paiement.</p></article></div>' );
		$out .= self::section( 'faq', 'Questions fréquentes', '<details><summary>Je n’ai pas encore reçu mon produit numérique.</summary><p>Vérifiez le statut de la commande et les informations saisies. Si la commande reste en cours après le délai annoncé sur la fiche produit, envoyez son numéro au support.</p></details><details><summary>J’ai saisi un mauvais identifiant de joueur ou numéro.</summary><p>Contactez-nous immédiatement. Une livraison déjà exécutée vers l’identifiant fourni ne peut généralement pas être annulée ou transférée.</p></details><details><summary>Mon paiement a été débité mais la commande a échoué.</summary><p>Envoyez le numéro de commande et la référence de paiement. Ne partagez pas l’intégralité de vos données bancaires.</p></details><details><summary>Comment demander un remboursement ?</summary><p>Écrivez au support avec le numéro de commande et le motif. L’éligibilité dépend de l’état de livraison, de la nature numérique du produit et des règles du fournisseur.</p></details><details><summary>Une personne prétend travailler pour Delicat.</summary><p>Ne communiquez aucun mot de passe ni code de sécurité par message. Utilisez uniquement les coordonnées publiées sur ce site et, lorsqu’une fiche produit exige exceptionnellement une information sensible de compte, uniquement son champ sécurisé dédié.</p></details>' );
		$out .= self::section( 'contact', 'Nous contacter', '<p>Pour toute demande, écrivez à ' . $email_link . '. Indiquez votre numéro de commande et une description précise. Les pièces jointes doivent masquer les informations financières sensibles.</p><p class="dni-note">Nous répondons dans les meilleurs délais. Les délais peuvent varier selon le volume de demandes et les vérifications nécessaires.</p>' );
		return $out . '</div>';
	}

	public static function terms(): string {
		/* RC81.2: delegate to the Legal module so a page still containing this
		 * shortcode renders the current document instead of text frozen in
		 * this file. Falls back to the page's own content when Legal is off. */
		if ( class_exists( 'Delicat_Builder_V9_Legal', false ) && is_callable( array( 'Delicat_Builder_V9_Legal', 'render' ) ) ) {
			$document = Delicat_Builder_V9_Legal::render( 'conditions' );
			if ( '' !== $document ) {
				return '<div class="dni-page dni-legal delicat-legal">' . self::hero( 'INFORMATIONS LÉGALES', 'Conditions d’utilisation' ) . '<div class="dni-legal__body">' . $document . '</div></div>';
			}
		}
		return '';
	}

	public static function privacy(): string {
		/* RC81.2: delegate to the Legal module so a page still containing this
		 * shortcode renders the current document instead of text frozen in
		 * this file. Falls back to the page's own content when Legal is off. */
		if ( class_exists( 'Delicat_Builder_V9_Legal', false ) && is_callable( array( 'Delicat_Builder_V9_Legal', 'render' ) ) ) {
			$document = Delicat_Builder_V9_Legal::render( 'privacy' );
			if ( '' !== $document ) {
				return '<div class="dni-page dni-legal delicat-legal">' . self::hero( 'INFORMATIONS LÉGALES', 'Politique de confidentialité' ) . '<div class="dni-legal__body">' . $document . '</div></div>';
			}
		}
		return '';
	}
}
