<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC81.1 — Legal pages.
 *
 * Ships nine legal documents and provisions them as real WordPress pages.
 *
 * The one rule this module exists to enforce: **it never silently overwrites a
 * page.** A published legal page is a document a customer may have relied on,
 * and a plugin upgrade quietly rewriting the refund policy is worse than no
 * automation at all. So:
 *
 *   - a missing page is created automatically on upgrade;
 *   - a page whose content still matches what the plugin shipped is refreshed
 *     in place when the document changes;
 *   - a page the site owner has edited is left exactly as it is, flagged as
 *     "personnalisée" in the admin, and only replaced on an explicit click.
 *
 * The comparison is a hash of the rendered body stored in post meta at write
 * time, so "edited" means edited — not merely re-saved by the block editor.
 *
 * Templates live in templates/legal/ as HTML with {{TOKENS}}. Tokens carry the
 * company identity, the three contact addresses and the resolved permalinks of
 * the other eight documents, so renaming a slug in WordPress keeps every
 * cross-reference working.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
 */
final class Delicat_Builder_V9_Legal {

	const OPTION       = 'delicat_builder_v9_legal';
	const META_HASH    = '_delicat_legal_hash';
	const META_DOC     = '_delicat_legal_doc';
	const META_VERSION = '_delicat_legal_version';

	/** Bump when a document's text changes; drives the refresh on upgrade. */
	const DOC_VERSION = '1.1.0';

	/**
	 * @return array<string,array<string,string>>
	 */
	public static function documents(): array {
		return array(
			'conditions'  => array(
				'file'  => 'conditions-utilisation.html',
				'slug'  => 'conditions-utilisation',
				'title' => "Conditions d'utilisation",
				'eyebrow' => 'INFORMATIONS LÉGALES',
				'intro' => "Règles applicables à l'accès au site et à l'achat de produits et services numériques.",
			),
			'privacy'     => array(
				'file'  => 'politique-confidentialite.html',
				'slug'  => 'politique-confidentialite',
				'title' => 'Politique de confidentialité',
				'eyebrow' => 'INFORMATIONS LÉGALES',
				'intro' => 'Les données que nous collectons, pourquoi, avec qui nous les partageons et combien de temps nous les gardons.',
			),
			'refund'      => array(
				'file'  => 'politique-remboursement.html',
				'slug'  => 'politique-de-remboursement',
				'title' => 'Politique de remboursement',
				'eyebrow' => 'INFORMATIONS LÉGALES',
				'intro' => "Ce qui est remboursable, ce qui ne l'est pas, et comment faire une demande.",
			),
			'shipping'    => array(
				'file'  => 'livraison-garantie.html',
				'slug'  => 'livraison-retours-garantie',
				'title' => 'Livraison, retours et garantie',
				'eyebrow' => 'PRODUITS ÉLECTRONIQUES',
				'intro' => 'Zones, délais, vérification à la réception, retour sous 7 jours et garantie des appareils.',
			),
			'cookies'     => array(
				'file'  => 'politique-cookies.html',
				'slug'  => 'politique-cookies',
				'title' => 'Politique de cookies',
				'eyebrow' => 'INFORMATIONS LÉGALES',
				'intro' => 'Les cookies réellement déposés par ce site, leur rôle et comment les refuser.',
			),
			'exchange'    => array(
				'file'  => 'conditions-exchange-wallet.html',
				'slug'  => 'conditions-exchange-wallet',
				'title' => 'Conditions Exchange et Wallet',
				'eyebrow' => 'SERVICES FINANCIERS',
				'intro' => "Règles applicables au Delicat Wallet et aux services d'échange de valeur numérique.",
			),
			'minors'      => array(
				'file'  => 'protection-des-mineurs.html',
				'slug'  => 'protection-des-mineurs',
				'title' => 'Protection des mineurs',
				'eyebrow' => 'ENGAGEMENT',
				'intro' => "Âge requis, engagement aux parents et mesures de prévention.",
			),
			'legalnotice' => array(
				'file'  => 'mentions-legales.html',
				'slug'  => 'mentions-legales',
				'title' => 'Mentions légales',
				'eyebrow' => 'INFORMATIONS LÉGALES',
				'intro' => "Identité de l'éditeur, hébergement et responsabilité éditoriale.",
			),
			'reviews'     => array(
				'file'  => 'politique-avis-clients.html',
				'slug'  => 'politique-avis-clients',
				'title' => 'Politique des avis clients',
				'eyebrow' => 'TRANSPARENCE',
				'intro' => "D'où viennent les avis publiés et comment ils sont modérés.",
			),
			'contest'     => array(
				'file'  => 'reglement-bon-kliyan.html',
				'slug'  => 'reglement-bon-kliyan',
				'title' => 'Règlement du programme Bon Kliyan',
				'eyebrow' => 'CONCOURS',
				'intro' => 'Conditions de participation, classement, lots et réclamation.',
			),
		);
	}

	public static function defaults(): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return array(
			'enabled'        => 1,
			'footer_links'   => 1,
			'replace_legacy' => 1,
			'company'        => get_bloginfo( 'name' ),
			'legal_form'     => '',
			'address'        => '',
			'tax_id'         => '',
			'publisher'      => '',
			'host_name'      => '',
			'host_address'   => '',
			'host_url'       => '',
			'whatsapp'       => '',
			'email_support'  => 'support@' . (string) $host,
			'email_privacy'  => 'privacy@' . (string) $host,
			'email_security' => 'security@' . (string) $host,
			'updated'        => '',
			'pages'          => array(),
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function boot(): void {
		add_action( 'delicat_builder_v9_version_purged', array( __CLASS__, 'sync_on_upgrade' ), 10, 2 );
		add_action( 'admin_post_delicat_builder_v9_legal_sync', array( __CLASS__, 'handle_sync' ) );
		add_filter( 'delicat_builder_v9_footer_legal_links', array( __CLASS__, 'footer_links' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	private static function template_dir(): string {
		return ( defined( 'DELICAT_BUILDER_V9_DIR' ) ? DELICAT_BUILDER_V9_DIR : '' ) . 'templates/legal/';
	}

	/**
	 * Resolve a document's public URL from the page we created for it, falling
	 * back to the default slug when the page does not exist yet.
	 */
	public static function url( string $key ): string {
		$docs = self::documents();
		if ( ! isset( $docs[ $key ] ) ) {
			return home_url( '/' );
		}
		$page_id = self::page_id( $key );
		if ( $page_id > 0 ) {
			$link = get_permalink( $page_id );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		return home_url( '/' . $docs[ $key ]['slug'] . '/' );
	}

	/** @var array<string,int> Per-request memo for page_id(); cleared by publish(). */
	private static array $resolved_pages = array();

	public static function page_id( string $key ): int {
		/* tokens() resolves ten cross-reference URLs per rendered document and
		 * the footer resolves them again; resolve each key once per request.
		 * publish() clears this, so a document created earlier in the same
		 * request is never reported missing by a later status() call. */
		if ( array_key_exists( $key, self::$resolved_pages ) ) {
			return self::$resolved_pages[ $key ];
		}
		$resolved =& self::$resolved_pages;

		$s  = self::settings();
		$id = isset( $s['pages'][ $key ] ) ? absint( $s['pages'][ $key ] ) : 0;
		if ( $id > 0 && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			$resolved[ $key ] = $id;
			return $id;
		}
		$docs = self::documents();
		if ( ! isset( $docs[ $key ] ) ) {
			$resolved[ $key ] = 0;
			return 0;
		}
		$found = get_page_by_path( $docs[ $key ]['slug'] );
		if ( $found instanceof WP_Post ) {
			$resolved[ $key ] = (int) $found->ID;
			return $resolved[ $key ];
		}

		/*
		 * pro.16: publish() stamps every generated document with META_DOC, but
		 * nothing ever read it, so resolution ended at the slug. Rename a legal
		 * page in WordPress — or lose the stored option row — and the plugin
		 * stopped finding its own page: url() then returned
		 * home_url( '/<original-slug>/' ), which is a 404 in the footer and
		 * inside the cross-references of every other legal document.
		 *
		 * The stamp is the durable identifier, so it is the last-resort lookup.
		 * It runs only when both fast paths have already failed, so the normal
		 * request pays nothing for it.
		 */
		$stamped = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_DOC, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- last-resort recovery lookup, never on the fast path.
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$resolved[ $key ] = ! empty( $stamped[0] ) ? (int) $stamped[0] : 0;
		return $resolved[ $key ];
	}

	private static function tokens(): array {
		$s    = self::settings();
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$wa   = preg_replace( '/[^0-9]/', '', (string) $s['whatsapp'] );

		$missing = static function ( string $value, string $label ): string {
			$value = trim( $value );
			return '' !== $value
				? esc_html( $value )
				: '<mark class="delicat-legal-todo">[' . esc_html( $label ) . ' — à compléter dans Réglages]</mark>';
		};

		$tokens = array(
			'{{COMPANY}}'        => esc_html( '' !== trim( (string) $s['company'] ) ? $s['company'] : get_bloginfo( 'name' ) ),
			'{{SITE_URL}}'       => esc_url( home_url() ),
			'{{SITE_HOST}}'      => esc_html( $host ),
			'{{UPDATED}}'        => esc_html( '' !== trim( (string) $s['updated'] ) ? $s['updated'] : date_i18n( 'j F Y' ) ),
			'{{EMAIL_SUPPORT}}'  => esc_html( (string) $s['email_support'] ),
			'{{EMAIL_PRIVACY}}'  => esc_html( (string) $s['email_privacy'] ),
			'{{EMAIL_SECURITY}}' => esc_html( (string) $s['email_security'] ),
			'{{WHATSAPP_URL}}'   => '' !== $wa ? esc_url( 'https://wa.me/' . $wa ) : esc_url( home_url( '/support/' ) ),
			'{{WHATSAPP_LABEL}}' => '' !== $wa ? esc_html( '+' . $wa ) : 'notre support',
			'{{LEGAL_FORM}}'     => $missing( (string) $s['legal_form'], 'Forme juridique' ),
			'{{ADDRESS}}'        => $missing( (string) $s['address'], 'Adresse du siège' ),
			'{{TAX_ID}}'         => $missing( (string) $s['tax_id'], 'Numéro fiscal ou patente' ),
			'{{PUBLISHER}}'      => $missing( (string) $s['publisher'], 'Directeur de la publication' ),
			'{{HOST_NAME}}'      => $missing( (string) $s['host_name'], "Nom de l'hébergeur" ),
			'{{HOST_ADDRESS}}'   => $missing( (string) $s['host_address'], "Adresse de l'hébergeur" ),
			'{{HOST_URL}}'       => $missing( (string) $s['host_url'], "Site de l'hébergeur" ),
		);

		$map = array(
			'{{URL_CONDITIONS}}' => 'conditions',
			'{{URL_PRIVACY}}'    => 'privacy',
			'{{URL_REFUND}}'     => 'refund',
			'{{URL_SHIPPING}}'   => 'shipping',
			'{{URL_COOKIES}}'    => 'cookies',
			'{{URL_EXCHANGE}}'   => 'exchange',
			'{{URL_MINORS}}'     => 'minors',
			'{{URL_LEGAL}}'      => 'legalnotice',
			'{{URL_REVIEWS}}'    => 'reviews',
			'{{URL_CONTEST}}'    => 'contest',
		);
		foreach ( $map as $token => $key ) {
			$tokens[ $token ] = esc_url( self::url( $key ) );
		}
		return $tokens;
	}

	public static function render( string $key ): string {
		$docs = self::documents();
		if ( ! isset( $docs[ $key ] ) ) {
			return '';
		}
		$path = self::template_dir() . $docs[ $key ]['file'];
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin template.
		if ( '' === trim( $body ) ) {
			return '';
		}
		$tokens = self::tokens();
		$body   = (string) str_replace( array_keys( $tokens ), array_values( $tokens ), $body );
		return self::chrome( $key, $body );
	}

	/**
	 * RC81.3 — page chrome.
	 *
	 * A legal page is long, and a wall of undifferentiated h2 is where people give
	 * up. Each document gets a hero carrying its eyebrow, title, intro and update
	 * date, and an automatically built table of contents from its own headings —
	 * anchored, so a support reply can link straight to "Retour sous 7 jours"
	 * instead of "see the shipping policy, somewhere".
	 *
	 * Built from the rendered HTML rather than maintained by hand, so a heading
	 * added to a template appears in its contents without anyone remembering to.
	 */
	private static function chrome( string $key, string $body ): string {
		$docs = self::documents();
		if ( ! isset( $docs[ $key ] ) ) {
			return $body;
		}
		$doc   = $docs[ $key ];
		$index = array();
		$used  = array();

		$body = (string) preg_replace_callback(
			'#<h2>(.*?)</h2>#s',
			static function ( array $m ) use ( &$index, &$used ): string {
				$label = trim( wp_strip_all_tags( $m[1] ) );
				$slug  = sanitize_title( $label );
				if ( '' === $slug ) {
					$slug = 'section';
				}
				$base = $slug;
				$n    = 2;
				while ( isset( $used[ $slug ] ) ) {
					$slug = $base . '-' . $n;
					$n++;
				}
				$used[ $slug ] = true;
				$index[]       = array( 'id' => $slug, 'label' => $label );
				return '<h2 id="' . esc_attr( $slug ) . '" class="delicat-legal__h2">'
					. $m[1]
					. '<a class="delicat-legal__anchor" href="#' . esc_attr( $slug ) . '" aria-label="Lien vers cette section">#</a></h2>';
			},
			$body
		);

		$toc = '';
		if ( count( $index ) > 2 ) {
			$items = '';
			foreach ( $index as $entry ) {
				$items .= '<li><a href="#' . esc_attr( $entry['id'] ) . '">' . esc_html( $entry['label'] ) . '</a></li>';
			}
			$toc = '<nav class="delicat-legal__toc" aria-label="Sommaire">'
				. '<p class="delicat-legal__toc-title">Sommaire</p><ol>' . $items . '</ol></nav>';
		}

		$s       = self::settings();
		$updated = '' !== trim( (string) $s['updated'] ) ? $s['updated'] : date_i18n( 'j F Y' );

		return '<div class="delicat-legal">'
			. '<header class="delicat-legal__hero">'
			. '<p class="delicat-legal__eyebrow">' . esc_html( $doc['eyebrow'] ) . '</p>'
			. '<h1 class="delicat-legal__title">' . esc_html( $doc['title'] ) . '</h1>'
			. '<p class="delicat-legal__intro">' . esc_html( $doc['intro'] ) . '</p>'
			. '<p class="delicat-legal__stamp"><span class="delicat-legal__dot"></span>Mise à jour ' . esc_html( $updated )
			. ' · ' . count( $index ) . ' sections</p>'
			. '</header>'
			. $toc
			. '<div class="delicat-legal__body">' . $body . '</div>'
			. '</div>';
	}

	private static function hash( string $body ): string {
		return md5( preg_replace( '/\s+/', ' ', trim( $body ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Provisioning                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Status of one document.
	 *
	 * missing     — no page exists
	 * current     — page matches what the plugin ships
	 * outdated    — page is untouched but the plugin text has moved on
	 * customized  — the site owner edited it; we do not touch it
	 */
	public static function status( string $key ): array {
		$page_id = self::page_id( $key );
		if ( $page_id <= 0 ) {
			return array( 'state' => 'missing', 'page_id' => 0 );
		}
		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			return array( 'state' => 'missing', 'page_id' => 0 );
		}
		$stored  = (string) get_post_meta( $page_id, self::META_HASH, true );
		$version = (string) get_post_meta( $page_id, self::META_VERSION, true );
		if ( '' === $stored ) {
			/* A page that predates this module — the two you already had. */
			return array( 'state' => 'customized', 'page_id' => $page_id );
		}
		if ( self::hash( (string) $post->post_content ) !== $stored ) {
			return array( 'state' => 'customized', 'page_id' => $page_id );
		}
		if ( $version !== self::DOC_VERSION ) {
			return array( 'state' => 'outdated', 'page_id' => $page_id );
		}
		return array( 'state' => 'current', 'page_id' => $page_id );
	}

	/**
	 * @param bool $force Replace even a customized page. Only ever true from an
	 *                    explicit, nonce-checked click in the admin.
	 */
	public static function install( string $key, bool $force = false ): string {
		$docs = self::documents();
		if ( ! isset( $docs[ $key ] ) ) {
			return 'unknown';
		}
		$body = self::render( $key );
		if ( '' === $body ) {
			return 'template_missing';
		}
		$doc     = $docs[ $key ];
		$status  = self::status( $key );
		$page_id = (int) $status['page_id'];

		if ( 'customized' === $status['state'] && ! $force ) {
			return 'skipped_customized';
		}
		if ( 'current' === $status['state'] && ! $force ) {
			return 'current';
		}

		$header = '<!-- wp:paragraph --><p class="delicat-legal-eyebrow">' . esc_html( $doc['eyebrow'] ) . '</p><!-- /wp:paragraph -->';
		unset( $header );

		$payload = array(
			'post_title'   => $doc['title'],
			'post_name'    => $doc['slug'],
			'post_content' => $body,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_excerpt' => $doc['intro'],
		);

		if ( $page_id > 0 ) {
			$payload['ID'] = $page_id;
			$result        = wp_update_post( $payload, true );
		} else {
			$result = wp_insert_post( $payload, true );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			return 'error';
		}
		$page_id = (int) $result;

		update_post_meta( $page_id, self::META_HASH, self::hash( $body ) );
		update_post_meta( $page_id, self::META_DOC, $key );
		update_post_meta( $page_id, self::META_VERSION, self::DOC_VERSION );

		$s                  = self::settings();
		$s['pages'][ $key ] = $page_id;
		update_option( self::OPTION, $s, true );
		/* A "publish all" run resolves each document again to report its state;
		 * without this the memo would still hold the pre-publish miss. */
		self::$resolved_pages = array();

		return $page_id === (int) $status['page_id'] ? 'updated' : 'created';
	}

	/**
	 * Runs once per plugin version, on the same hook rc.80 uses to purge.
	 *
	 * Creates what is missing and refreshes what is untouched. Never replaces a
	 * page the owner has edited — those wait for a click.
	 */
	public static function sync_on_upgrade( $current = '', $previous = '' ): void {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}
		unset( $current, $previous );

		/* RC81.2 — adopt the pages that predate this module.
		 *
		 * The two legal pages already on the site were not written by a human:
		 * their body is the single shortcode [delicat_native_terms] or
		 * [delicat_native_privacy], and the text a visitor read came from PHP
		 * frozen inside class-delicat-builder-native-pages.php. So "customized"
		 * was the wrong reading of them — there was nothing of the owner's to
		 * protect, which is why the new documents kept appearing to be ignored.
		 *
		 * A page whose entire body is one of those shortcodes, or which is empty,
		 * is therefore adopted and rewritten. A page carrying real prose is still
		 * left alone and still needs an explicit click.
		 *
		 * wp_update_post() stores the previous body as a WordPress revision, so
		 * the old wording remains recoverable from the editor. Replaced, not
		 * destroyed — a legal page is a document someone may have relied on. */
		if ( ! empty( $s['replace_legacy'] ) ) {
			foreach ( array_keys( self::documents() ) as $key ) {
				$page_id = self::page_id( $key );
				if ( $page_id <= 0 ) {
					continue;
				}
				if ( '' !== (string) get_post_meta( $page_id, self::META_HASH, true ) ) {
					continue; // already ours
				}
				if ( self::is_legacy_stub( $page_id ) ) {
					self::install( $key, true );
				}
			}
		}

		foreach ( array_keys( self::documents() ) as $key ) {
			self::install( $key, false );
		}
		/* Cross-references resolve to real permalinks only once every page
		 * exists, so the first pass is followed by a second that rewrites the
		 * links. Pages the owner edited are still skipped. */
		foreach ( array_keys( self::documents() ) as $key ) {
			$status = self::status( $key );
			if ( 'customized' === $status['state'] ) {
				continue;
			}
			$body = self::render( $key );
			$post = get_post( (int) $status['page_id'] );
			if ( '' === $body || ! $post instanceof WP_Post ) {
				continue;
			}
			if ( self::hash( (string) $post->post_content ) === self::hash( $body ) ) {
				continue;
			}
			wp_update_post( array( 'ID' => (int) $status['page_id'], 'post_content' => $body ) );
			update_post_meta( (int) $status['page_id'], self::META_HASH, self::hash( $body ) );
		}
	}

	/**
	 * A page holding nothing but a generated shortcode, or nothing at all, has no
	 * authored content to protect.
	 */
	private static function is_legacy_stub( int $page_id ): bool {
		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		$body = trim( (string) $post->post_content );
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			return true;
		}
		$stripped = trim( preg_replace( '/\[delicat_native_(terms|privacy|support)\]/', '', $body ) );
		return '' === trim( wp_strip_all_tags( (string) $stripped ) );
	}

	public static function handle_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_legal_sync' );

		$key   = isset( $_POST['doc'] ) ? sanitize_key( wp_unslash( $_POST['doc'] ) ) : '';
		$force = isset( $_POST['force'] ) && '1' === $_POST['force'];

		if ( 'all' === $key ) {
			foreach ( array_keys( self::documents() ) as $k ) {
				self::install( $k, false );
			}
			$notice = 'synced';
		} else {
			$result = self::install( $key, $force );
			$notice = in_array( $result, array( 'created', 'updated' ), true ) ? 'synced' : $result;
		}

		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'purge_everything' ) ) ) {
			Delicat_Builder_V9_Cache::purge_everything();
		}

		wp_safe_redirect( add_query_arg( 'delicat_legal', $notice, wp_get_referer() ? wp_get_referer() : admin_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Footer                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The footer listed two documents. Nine exist now, and a refund policy
	 * nobody can find is a refund policy that does not protect anyone.
	 *
	 * @param array<string,string> $links
	 * @return array<string,string>
	 */
	public static function footer_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		$s     = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['footer_links'] ) ) {
			return $links;
		}
		$order = array( 'conditions', 'privacy', 'refund', 'shipping', 'cookies', 'legalnotice' );
		if ( self::page_id( 'exchange' ) > 0 ) {
			$order[] = 'exchange';
		}
		$docs = self::documents();
		/* RC87: the footer fallback uses the key `terms`, while this module used
		 * `conditions`; retaining both rendered the same URL twice. */
		if ( self::page_id( 'conditions' ) > 0 ) {
			unset( $links['terms'] );
		}
		foreach ( $order as $key ) {
			if ( ! isset( $docs[ $key ] ) || self::page_id( $key ) <= 0 ) {
				continue;
			}
			$links[ $key ] = array(
				'label' => $docs[ $key ]['title'],
				'url'   => self::url( $key ),
			);
		}
		return $links;
	}
}

Delicat_Builder_V9_Legal::boot();
