<?php
/**
 * Legal pages. Ten documents ship with the theme under legal/, written for
 * this store (digital goods, wallet, minors, Bon Kliyan). Each is installed
 * as a WordPress page whose content is a shortcode, so the text is rendered
 * at view time with the Customizer's company details — edit the details once
 * and every page follows.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

const DS_LEGAL_META = '_ds_legal_doc';

/**
 * @return array<string,array{file:string,slug:string,title:string,eyebrow:string,intro:string}>
 */
function ds_legal_documents(): array {
	return array(
		'conditions'  => array(
			'file'    => 'conditions-utilisation.html',
			'slug'    => 'conditions-utilisation',
			'title'   => "Conditions d'utilisation",
			'eyebrow' => 'INFORMATIONS LÉGALES',
			'intro'   => "Règles applicables à l'accès au site et à l'achat de produits et services numériques.",
		),
		'privacy'     => array(
			'file'    => 'politique-confidentialite.html',
			'slug'    => 'politique-confidentialite',
			'title'   => 'Politique de confidentialité',
			'eyebrow' => 'INFORMATIONS LÉGALES',
			'intro'   => 'Les données que nous collectons, pourquoi, avec qui nous les partageons et combien de temps nous les gardons.',
		),
		'refund'      => array(
			'file'    => 'politique-remboursement.html',
			'slug'    => 'politique-de-remboursement',
			'title'   => 'Politique de remboursement',
			'eyebrow' => 'INFORMATIONS LÉGALES',
			'intro'   => "Ce qui est remboursable, ce qui ne l'est pas, et comment faire une demande.",
		),
		'shipping'    => array(
			'file'    => 'livraison-garantie.html',
			'slug'    => 'livraison-retours-garantie',
			'title'   => 'Livraison, retours et garantie',
			'eyebrow' => 'PRODUITS ÉLECTRONIQUES',
			'intro'   => 'Zones, délais, vérification à la réception, retour sous 7 jours et garantie des appareils.',
		),
		'cookies'     => array(
			'file'    => 'politique-cookies.html',
			'slug'    => 'politique-cookies',
			'title'   => 'Politique de cookies',
			'eyebrow' => 'INFORMATIONS LÉGALES',
			'intro'   => 'Les cookies réellement déposés par ce site, leur rôle et comment les refuser.',
		),
		'exchange'    => array(
			'file'    => 'conditions-exchange-wallet.html',
			'slug'    => 'conditions-exchange-wallet',
			'title'   => 'Conditions Exchange et Wallet',
			'eyebrow' => 'SERVICES FINANCIERS',
			'intro'   => "Règles applicables au Delicat Wallet et aux services d'échange de valeur numérique.",
		),
		'minors'      => array(
			'file'    => 'protection-des-mineurs.html',
			'slug'    => 'protection-des-mineurs',
			'title'   => 'Protection des mineurs',
			'eyebrow' => 'ENGAGEMENT',
			'intro'   => 'Âge requis, engagement aux parents et mesures de prévention.',
		),
		'legalnotice' => array(
			'file'    => 'mentions-legales.html',
			'slug'    => 'mentions-legales',
			'title'   => 'Mentions légales',
			'eyebrow' => 'INFORMATIONS LÉGALES',
			'intro'   => "Identité de l'éditeur, hébergement et responsabilité éditoriale.",
		),
		'reviews'     => array(
			'file'    => 'politique-avis-clients.html',
			'slug'    => 'politique-avis-clients',
			'title'   => 'Politique des avis clients',
			'eyebrow' => 'TRANSPARENCE',
			'intro'   => "D'où viennent les avis publiés et comment ils sont modérés.",
		),
		'contest'     => array(
			'file'    => 'reglement-bon-kliyan.html',
			'slug'    => 'reglement-bon-kliyan',
			'title'   => 'Règlement du programme Bon Kliyan',
			'eyebrow' => 'CONCOURS',
			'intro'   => 'Conditions de participation, classement, lots et réclamation.',
		),
	);
}

/**
 * Page id of a document (by stamp, then by slug), 0 when not installed.
 */
function ds_legal_page_id( string $key ): int {
	static $cache = array();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$docs = ds_legal_documents();
	if ( ! isset( $docs[ $key ] ) ) {
		return $cache[ $key ] = 0;
	}
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'numberposts'    => 1,
			'fields'         => 'ids',
			'meta_key'       => DS_LEGAL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'no_found_rows'  => true,
		)
	);
	if ( ! empty( $found[0] ) ) {
		return $cache[ $key ] = (int) $found[0];
	}
	$page = get_page_by_path( $docs[ $key ]['slug'] );
	return $cache[ $key ] = ( $page instanceof WP_Post && 'publish' === $page->post_status ) ? (int) $page->ID : 0;
}

/**
 * URL of a document's page; falls back to its intended slug.
 */
function ds_legal_url( string $key ): string {
	$id = ds_legal_page_id( $key );
	if ( $id > 0 ) {
		$link = get_permalink( $id );
		if ( is_string( $link ) ) {
			return $link;
		}
	}
	$docs = ds_legal_documents();
	return isset( $docs[ $key ] ) ? home_url( '/' . $docs[ $key ]['slug'] . '/' ) : home_url( '/' );
}

/**
 * Replace {{TOKENS}} in a document body. Pure.
 *
 * @param array<string,string> $tokens {{NAME}} => replacement (already escaped).
 */
function ds_legal_replace( string $body, array $tokens ): string {
	return strtr( $body, $tokens );
}

/**
 * Token values from the Customizer. A missing mandatory detail is rendered
 * as a highlighted "à compléter" so it cannot go unnoticed.
 *
 * @return array<string,string>
 */
function ds_legal_tokens(): array {
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$wa      = ds_whatsapp_number();
	$support = ds_support_email();
	$missing = static function ( string $value, string $label ): string {
		$value = trim( $value );
		return '' !== $value ? esc_html( $value ) : '<mark class="ds-legal-todo">[' . esc_html( $label ) . ' — à compléter dans Apparence › Personnaliser › Delicat Store › Informations légales]</mark>';
	};
	$company = trim( (string) ds_opt( 'legal_company' ) );
	$updated = trim( (string) ds_opt( 'legal_updated' ) );
	$tokens  = array(
		'{{COMPANY}}'        => esc_html( '' !== $company ? $company : get_bloginfo( 'name' ) ),
		'{{SITE_URL}}'       => esc_url( home_url() ),
		'{{SITE_HOST}}'      => esc_html( $host ),
		'{{UPDATED}}'        => esc_html( '' !== $updated ? $updated : date_i18n( 'j F Y' ) ),
		'{{EMAIL_SUPPORT}}'  => esc_html( $support ),
		'{{EMAIL_PRIVACY}}'  => esc_html( '' !== trim( (string) ds_opt( 'legal_email_privacy' ) ) ? (string) ds_opt( 'legal_email_privacy' ) : $support ),
		'{{EMAIL_SECURITY}}' => esc_html( '' !== trim( (string) ds_opt( 'legal_email_security' ) ) ? (string) ds_opt( 'legal_email_security' ) : $support ),
		'{{WHATSAPP_URL}}'   => '' !== $wa ? esc_url( 'https://wa.me/' . $wa ) : esc_url( ds_page_url( 'support' ) ?: home_url( '/support/' ) ),
		'{{WHATSAPP_LABEL}}' => '' !== $wa ? esc_html( '+' . $wa ) : 'notre support',
		'{{LEGAL_FORM}}'     => $missing( (string) ds_opt( 'legal_form' ), 'Forme juridique' ),
		'{{ADDRESS}}'        => $missing( (string) ds_opt( 'legal_address' ), 'Adresse du siège' ),
		'{{TAX_ID}}'         => $missing( (string) ds_opt( 'legal_tax_id' ), 'Numéro fiscal ou patente' ),
		'{{PUBLISHER}}'      => $missing( (string) ds_opt( 'legal_publisher' ), 'Directeur de la publication' ),
		'{{HOST_NAME}}'      => $missing( (string) ds_opt( 'legal_host_name' ), "Nom de l'hébergeur" ),
		'{{HOST_ADDRESS}}'   => $missing( (string) ds_opt( 'legal_host_address' ), "Adresse de l'hébergeur" ),
		'{{HOST_URL}}'       => $missing( (string) ds_opt( 'legal_host_url' ), "Site de l'hébergeur" ),
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
		$tokens[ $token ] = esc_url( ds_legal_url( $key ) );
	}
	return $tokens;
}

/**
 * Rendered body of a document.
 */
function ds_legal_render( string $key ): string {
	$docs = ds_legal_documents();
	if ( ! isset( $docs[ $key ] ) ) {
		return '';
	}
	$path = DS_DIR . 'legal/' . $docs[ $key ]['file'];
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- theme file.
	if ( '' === trim( $body ) ) {
		return '';
	}
	$html = ds_legal_replace( $body, ds_legal_tokens() );
	$html = str_replace( 'href="/support/"', 'href="' . esc_url( ds_page_url( 'support' ) ?: home_url( '/support/' ) ) . '"', $html );
	return '<div class="ds-legal">' . $html . '</div>';
}

add_shortcode(
	'delicat_legal',
	static function ( $atts ): string {
		$atts = shortcode_atts( array( 'doc' => '' ), $atts );
		return ds_legal_render( sanitize_key( (string) $atts['doc'] ) );
	}
);

/**
 * Document key of the current page, '' if not a legal page.
 */
function ds_legal_current_key(): string {
	if ( ! is_page() ) {
		return '';
	}
	$key = (string) get_post_meta( get_queried_object_id(), DS_LEGAL_META, true );
	return isset( ds_legal_documents()[ $key ] ) ? $key : '';
}

/**
 * Footer "Informations légales" links, only for installed pages.
 *
 * @return array<string,string> title => url
 */
function ds_legal_footer_links(): array {
	$out = array();
	foreach ( ds_legal_documents() as $key => $doc ) {
		if ( ds_legal_page_id( $key ) > 0 ) {
			$out[ $doc['title'] ] = ds_legal_url( $key );
		}
	}
	return $out;
}
