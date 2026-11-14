<?php
/**
 * Delicat Rewards — dashboard (WooCommerce › Delicat Rewards, Meilleur client, Participants, Licences).
 * Loaded by delicat-rewards.php in wp-admin only (admin.php, admin-post.php); never for customers.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p><b>Delicat Rewards :</b> WooCommerce doit être actif.</p></div>';
	}
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( delicat_cap() ) || ! get_transient( 'delicat_rewards_setup_notice' ) ) return;
	delete_transient( 'delicat_rewards_setup_notice' );
	$url = admin_url( 'admin.php?page=delicat-rewards&tab=installation' );
	echo '<div class="notice notice-success is-dismissible"><p><b>Delicat Rewards v' . esc_html( DELICAT_REWARDS_VERSION ) . ' prêt.</b> Avant les récompenses réelles, ouvrez le guide d’installation et vérifiez les PINs, les top-ups directs et les variations serveur. <a class="button button-small" style="margin-left:8px" href="' . esc_url( $url ) . '">Configurer maintenant</a></p></div>';
} );

/* =========================================================================
   ADMIN — SHARED HELPERS (guards, notices, audit log, sanitising)
   ========================================================================= */

/** Every write goes through this: capability + nonce + referer, or die. */
function delicat_guard( $action ) {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}
	check_admin_referer( $action ); // nonce + referer; dies on failure
}

/** PRG: redirect back to a tab after a write (no double-submit on refresh). */
function delicat_back( $tab, $errors = array(), $notes = array() ) {
	delicat_notice_set( $errors, $notes );
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'delicat-rewards', 'tab' => sanitize_key( $tab ) ),
		admin_url( 'admin.php' )
	) );
	exit;
}

function delicat_notice_set( $errors = array(), $notes = array() ) {
	$errors = array_values( array_filter( (array) $errors ) );
	$notes  = array_values( array_filter( (array) $notes ) );
	if ( ! $errors && ! $notes ) return;
	set_transient( 'delicat_notice_' . get_current_user_id(), array( 'e' => $errors, 'n' => $notes ), 120 );
}

function delicat_notice_show() {
	$k = 'delicat_notice_' . get_current_user_id();
	$n = get_transient( $k );
	if ( ! $n || ! is_array( $n ) ) return;
	delete_transient( $k );
	if ( ! empty( $n['n'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . implode( '<br>', array_map( 'esc_html', $n['n'] ) ) . '</p></div>';
	}
	if ( ! empty( $n['e'] ) ) {
		echo '<div class="notice notice-warning is-dismissible"><p><b>À vérifier :</b><br>' . implode( '<br>', array_map( 'esc_html', $n['e'] ) ) . '</p></div>';
	}
}

/** "197, 289" → array( valid ids ), array( rejected ids ) */
function delicat_parse_ids( $raw, $historical = false ) {
	$ids = array(); $bad = array();
	foreach ( preg_split( '/[^0-9]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $chunk ) {
		$id = (int) $chunk;
		if ( $id <= 0 ) continue;
		if ( $historical || delicat_valid_pid( $id ) ) $ids[ $id ] = true;
		else                            $bad[ $id ] = true;
	}
	return array( array_keys( $ids ), array_keys( $bad ) );
}

/** "✅ Nom du produit (#289)" or a loud warning. Handles variations. */
function delicat_pid_hint( $id ) {
	$id = (int) $id;
	if ( ! $id ) return '<span style="color:#777">— (résolu par slug)</span>';
	if ( ! delicat_valid_pid( $id ) ) {
		return '<b>#' . $id . ' — ID historique / produit indisponible</b>'
			. '<div class="hint">Les commandes conservant cet ID peuvent compter. Vérifiez le pack dans vos anciennes commandes.</div>';
	}

	$name = '';
	if ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $id );
		if ( $p ) $name = $p->get_name(); // for a variation: "PIN Free Fire - 110 Diamants"
	}
	if ( '' === $name ) $name = get_the_title( $id );

	$parent = delicat_parent_pid( $id );
	$edit   = admin_url( 'post.php?post=' . $parent . '&action=edit' );
	$tag    = '';
	if ( delicat_is_variation( $id ) ) {
		$tag = ' <span class="hint">· variation</span>';
	} elseif ( function_exists( 'wc_get_product' ) ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_type( 'variable' ) ) {
			$tag = ' <span class="hint">· produit variable (' . count( $p->get_children() ) . ' variations, toutes comptées)</span>';
		}
	}
	return '✅ <a href="' . esc_url( $edit ) . '" target="_blank">' . esc_html( $name ) . '</a> <code>#' . $id . '</code>' . $tag;
}

/** Datalist of products AND their variations, so the admin picks instead of typing. */
function delicat_products_datalist() {
	static $done = false;
	if ( $done || ! function_exists( 'wc_get_products' ) ) return;
	$done = true;

	$ids  = wc_get_products( array( 'limit' => 200, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'return' => 'ids' ) );
	$rows = array();
	foreach ( (array) $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) continue;
		$rows[ (int) $id ] = $p->get_name() . ' (#' . (int) $id . ')';

		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) continue;
				$rows[ (int) $vid ] = '↳ ' . $v->get_name() . ' (#' . (int) $vid . ')';
				if ( count( $rows ) > 500 ) break 2;
			}
		}
	}

	echo '<datalist id="dlc-products">';
	foreach ( $rows as $id => $label ) {
		echo '<option value="' . (int) $id . '">' . esc_attr( $label ) . '</option>';
	}
	echo '</datalist>';
}

/** Everything currently broken in the configuration (used by notices + diagnostics). */
function delicat_config_problems() {
	$out = array();
	foreach ( (array) delicat_cfg( 'pins' ) as $denom => $pid ) {
		if ( ! delicat_pid_by_diamonds( $denom ) && delicat_reward_value( $denom ) <= 0 ) {
			$out[] = sprintf( 'Aucun prix HTG pour %d diamants — configurez son montant dans Réglages.', (int) $denom );
		}
	}
	$owners = array(); $mapped = array();
	foreach ( delicat_tiers() as $tk => $tt ) foreach ( $tt['product_ids'] as $pid ) { $pid = (int) $pid; $owners[ $pid ][] = $tk; $mapped[] = array( 'tier' => $tk, 'pid' => $pid ); }
	foreach ( $owners as $pid => $ks ) if ( count( array_unique( $ks ) ) > 1 ) $out[] = sprintf( 'Produit #%d affecté à plusieurs tiers (%s) — un même achat peut progresser dans plusieurs récompenses.', $pid, implode( ', ', array_unique( $ks ) ) );
	/* Also catch parent-vs-variation overlap: runtime order matching contains both
	 * IDs, so parent #100 in one tier + variation #123 (child of #100) in another
	 * would double-count even though the numeric IDs are different. */
	$semantic_seen = array();
	for ( $i = 0, $n = count( $mapped ); $i < $n; $i++ ) for ( $j = $i + 1; $j < $n; $j++ ) {
		$a = $mapped[ $i ]; $b = $mapped[ $j ]; if ( $a['tier'] === $b['tier'] || $a['pid'] === $b['pid'] ) continue;
		$overlap = ( ! delicat_is_variation( $a['pid'] ) && delicat_parent_pid( $b['pid'] ) === $a['pid'] ) || ( ! delicat_is_variation( $b['pid'] ) && delicat_parent_pid( $a['pid'] ) === $b['pid'] );
		if ( $overlap ) { $sk = min( $a['pid'], $b['pid'] ) . ':' . max( $a['pid'], $b['pid'] ); if ( ! isset( $semantic_seen[ $sk ] ) ) { $semantic_seen[ $sk ] = 1; $out[] = sprintf( 'Chevauchement parent/variation #%d ↔ #%d entre tiers %s / %s — mappez les variations exactes pour éviter un double comptage.', $a['pid'], $b['pid'], $a['tier'], $b['tier'] ); } }
	}
	foreach ( delicat_tiers() as $key => $t ) {
		if ( ! $t['product_ids'] ) {
			$out[] = sprintf( 'Tier « %s » : aucun produit acheté valide — la progression restera à 0.', $t['label'] );
		}
		if ( $t['reward'] > 0 && delicat_reward_value( $t['reward'] ) <= 0 ) {
			$out[] = sprintf( 'Tier « %s » : le montant HTG pour %d diamants doit être configuré.', $t['label'], $t['reward'] );
		}
	}
	if ( 'order' === delicat_cfg( 'reward_method' ) ) {
		foreach ( delicat_meilleur_prizes() as $rk => $pv ) {
			if ( $pv > 0 && ! delicat_reward_plan( $pv ) ) {
				$out[] = sprintf( 'Prix Meilleur Client (place %d) : %d 💎 ne peut pas être composé avec vos PINs.', $rk, $pv );
			}
		}
	}
	return $out;
}

/**
 * 4.1.0: the notice shown on every admin screen reuses the last check for 10 minutes (it loads every
 * mapped product); a settings change checks again at once. Diagnostics always checks live.
 */
function delicat_config_problems_cached() {
	$gen    = delicat_cfg_gen();
	$cached = get_transient( 'delicat_cfg_problems' );
	if ( is_array( $cached ) && isset( $cached['gen'], $cached['p'] ) && (int) $cached['gen'] === $gen && is_array( $cached['p'] ) ) return $cached['p'];
	$p = delicat_config_problems();
	set_transient( 'delicat_cfg_problems', array( 'gen' => $gen, 'p' => $p ), 10 * MINUTE_IN_SECONDS );
	return $p;
}

add_action( 'admin_notices', function () {
	if ( ! current_user_can( delicat_cap() ) || ! class_exists( 'WooCommerce' ) ) return;

	$tamper = get_option( 'delicat_tamper', array() );
	if ( is_array( $tamper ) && $tamper ) {
		echo '<div class="notice notice-error"><p><b>Delicat Rewards — alerte d\'intégrité :</b> '
			. (int) count( $tamper ) . ' compteur(s) de bonus modifié(s) hors du plugin. Les réclamations concernées sont gelées. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=delicat-rewards&tab=diagnostics' ) ) . '">Voir</a></p></div>';
	}

	if ( (int) delicat_cfg( 'dry_run' ) === 1 ) {
		echo '<div class="notice notice-warning"><p><b>Delicat Rewards — 🧪 MODE TEST ACTIF :</b> aucune récompense n\'est livrée aux clients. '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=delicat-rewards&tab=reglages' ) ) . '">Désactiver</a></p></div>';
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false !== strpos( (string) $screen->id, 'delicat-rewards' ) ) return; // the page shows them itself
	$p = delicat_config_problems_cached();
	if ( ! $p ) return;
	$url = admin_url( 'admin.php?page=delicat-rewards&tab=diagnostics' );
	echo '<div class="notice notice-warning"><p><b>Delicat Rewards :</b> ' . esc_html( $p[0] )
		. ( count( $p ) > 1 ? ' (+' . ( count( $p ) - 1 ) . ' autre' . ( count( $p ) > 2 ? 's' : '' ) . ')' : '' )
		. ' <a href="' . esc_url( $url ) . '">Ouvrir les diagnostics</a></p></div>';
} );


/* ---- Branding: editable store name and Media Library logo ---- */
add_action( 'admin_post_delicat_save_branding', function () {
	delicat_guard( 'delicat_save_branding' );
	$cfg = delicat_cfg();
	$name = delicat_txt( wp_unslash( $_POST['brand_name'] ?? '' ) );
	$logo_id = absint( $_POST['brand_logo_id'] ?? 0 );
	if ( $logo_id && 'attachment' !== get_post_type( $logo_id ) ) $logo_id = 0;
	$cfg['brand_name'] = $name ?: get_bloginfo( 'name' );
	$cfg['brand_logo_id'] = $logo_id;
	delicat_cfg_save( $cfg );
	delicat_audit_add( sprintf( 'Identité visuelle mise à jour : %s, logo #%d.', $cfg['brand_name'], $logo_id ) );
	delicat_back( 'branding', array(), array( 'Nom et logo enregistrés.' ) );
} );

/* =========================================================================
   ADMIN — MENU
   ========================================================================= */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Delicat Rewards', 'Delicat Rewards', delicat_cap(), 'delicat-rewards', 'delicat_admin_page' );
	add_submenu_page( 'woocommerce', 'Delicat — Meilleur client', 'Delicat Meilleur client', delicat_cap(), 'delicat-meilleur', function () { $_GET['tab'] = 'meilleur'; delicat_admin_page(); } );
	add_submenu_page( 'woocommerce', 'Delicat — Participants', 'Delicat Participants', delicat_cap(), 'delicat-participants', 'delicat_participants_page' );
} );

/* =========================================================================
   ADMIN — WRITE HANDLERS (admin-post.php: capability + nonce + validation)
   ========================================================================= */

/* ---- Tiers: goals, rewards, product IDs, limits, order, add/remove ---- */
add_action( 'admin_post_delicat_save_tiers', function () {
	delicat_guard( 'delicat_save_tiers' );

	$cfg  = delicat_cfg();
	$old  = (array) $cfg['tiers'];
	$in   = ( isset( $_POST['tiers'] ) && is_array( $_POST['tiers'] ) ) ? wp_unslash( $_POST['tiers'] ) : array();
	$errs = array();
	$notes= array();
	$new  = array();
	$diff = array();

	foreach ( $in as $rawkey => $row ) {
		$key = sanitize_key( $rawkey );
		if ( '' === $key || ! isset( $old[ $key ] ) || ! is_array( $row ) ) continue; // only keys we already know

		if ( ! empty( $row['delete'] ) ) {
			$notes[] = sprintf( 'Tier « %s » supprimé.', $old[ $key ]['label'] ?? $key );
			$diff[]  = 'suppression:' . $key;
			continue;
		}

		$label   = delicat_txt( $row['label'] ?? '' ) ?: ( $old[ $key ]['label'] ?? $key );
		$goal    = max( 1, min( DELICAT_MAX_GOAL, (int) ( $row['goal'] ?? 1 ) ) );
		$reward  = max( 0, min( DELICAT_MAX_REWARD, (int) ( $row['reward'] ?? 0 ) ) );
		$maxc    = max( 0, min( 999, (int) ( $row['max_claims'] ?? 0 ) ) );
		$pos     = max( 0, min( 99, (int) ( $row['order'] ?? 0 ) ) );
		$pin     = max( 0, min( 1000000, (int) ( $row['pin'] ?? 0 ) ) );
		$enabled = empty( $row['enabled'] ) ? 0 : 1;

		list( $ids, $bad ) = delicat_parse_ids( $row['ids'] ?? '', true );
		foreach ( $bad as $b ) {
			$errs[] = sprintf( 'Tier « %s » : #%d n\'est ni un produit ni une variation publiée — ignoré.', $label, $b );
		}

		if ( ! $ids && ! delicat_pid_by_diamonds( $pin ) && $enabled ) {
			$enabled = 0;
			$errs[]  = sprintf( 'Tier « %s » désactivé : aucun produit valide à compter.', $label );
		}
		if ( $enabled && $reward > 0 && 'order' === $cfg['reward_method'] && ! delicat_reward_plan( $reward ) ) {
			$enabled = 0;
			$errs[]  = sprintf( 'Tier « %s » désactivé : %d 💎 ne peut pas être composé avec vos PINs (%s).',
				$label, $reward, implode( ', ', delicat_ff_denoms() ) );
		}

		$o = $old[ $key ];
		if ( (int) ( $o['goal'] ?? 0 ) !== $goal )       $diff[] = sprintf( '%s objectif %d→%d', $key, (int) ( $o['goal'] ?? 0 ), $goal );
		if ( (int) ( $o['reward'] ?? 0 ) !== $reward )   $diff[] = sprintf( '%s récompense %d→%d', $key, (int) ( $o['reward'] ?? 0 ), $reward );
		$oldids = implode( ',', array_map( 'intval', (array) ( $o['ids'] ?? array() ) ) );
		if ( $oldids !== implode( ',', $ids ) )          $diff[] = sprintf( '%s produits [%s]→[%s]', $key, $oldids, implode( ',', $ids ) );
		if ( (int) ( $o['enabled'] ?? 0 ) !== $enabled ) $diff[] = sprintf( '%s %s', $key, $enabled ? 'activé' : 'désactivé' );

		if ( $goal < (int) ( $o['goal'] ?? 0 ) ) {
			$errs[] = sprintf( 'Tier « %s » : objectif abaissé (%d→%d). Les clients qui ont déjà assez d\'achats ce mois-ci peuvent réclamer immédiatement.',
				$label, (int) $o['goal'], $goal );
		}

		$new[ $key ] = array(
			'label'      => $label,
			'title'      => delicat_txt( $row['title'] ?? '' ),
			'sub'        => delicat_txt( $row['sub'] ?? '' ),
			'ids'        => $ids,
			'pin'        => $pin,
			'goal'       => $goal,
			'reward'     => $reward,
			'max_claims' => $maxc,
			'enabled'    => $enabled,
			'order'      => $pos,
		);
	}

	/* ---- new tier ---- */
	$nt = ( isset( $_POST['newtier'] ) && is_array( $_POST['newtier'] ) ) ? wp_unslash( $_POST['newtier'] ) : array();
	$nlabel = delicat_txt( $nt['label'] ?? '' );
	if ( '' !== $nlabel ) {
		if ( count( $new ) >= DELICAT_MAX_TIERS ) {
			$errs[] = sprintf( 'Maximum %d tiers — nouveau tier non ajouté.', DELICAT_MAX_TIERS );
		} else {
			$key = sanitize_key( sanitize_title( $nlabel ) );
			if ( '' === $key ) $key = 'tier';
			$base = $key; $i = 2;
			while ( isset( $new[ $key ] ) || isset( $old[ $key ] ) ) { $key = $base . '-' . $i; $i++; }

			list( $ids, $bad ) = delicat_parse_ids( $nt['ids'] ?? '', true );
			foreach ( $bad as $b ) $errs[] = sprintf( 'Nouveau tier : produit #%d inexistant — ignoré.', $b );

			$reward  = max( 0, min( DELICAT_MAX_REWARD, (int) ( $nt['reward'] ?? 0 ) ) );
			$pin     = max( 0, min( 1000000, (int) ( $nt['pin'] ?? 0 ) ) );
			$enabled = ( $ids || delicat_pid_by_diamonds( $pin ) ) ? 1 : 0;
			if ( $enabled && $reward > 0 && 'order' === $cfg['reward_method'] && ! delicat_reward_plan( $reward ) ) {
				$enabled = 0;
				$errs[]  = sprintf( 'Nouveau tier créé mais désactivé : %d 💎 non composable.', $reward );
			}
			if ( ! $enabled && ! $ids ) $errs[] = 'Nouveau tier créé mais désactivé : aucun produit valide.';

			$new[ $key ] = array(
				'label'      => $nlabel,
				'title'      => delicat_txt( $nt['title'] ?? 'Free Fire' ),
				'sub'        => delicat_txt( $nt['sub'] ?? '' ),
				'ids'        => $ids,
				'pin'        => $pin,
				'goal'       => max( 1, min( DELICAT_MAX_GOAL, (int) ( $nt['goal'] ?? 10 ) ) ),
				'reward'     => $reward,
				'max_claims' => max( 0, min( 999, (int) ( $nt['max_claims'] ?? 0 ) ) ),
				'enabled'    => $enabled,
				'order'      => count( $new ) + 1,
			);
			$notes[] = sprintf( 'Tier « %s » créé (clé permanente : %s).', $nlabel, $key );
			$diff[]  = 'création:' . $key;
		}
	}

	if ( ! $new ) {
		delicat_back( 'tiers', array( 'Refusé : la configuration doit garder au moins un tier.' ) );
	}

	$cfg['tiers'] = $new;
	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Tiers : ' . implode( ' · ', $diff ) );
	$notes[] = 'Tiers enregistrés.';
	delicat_back( 'tiers', $errs, $notes );
} );

/* ---- Products: diamond amount → WooCommerce product ID ---- */
add_action( 'admin_post_delicat_save_pins', function () {
	delicat_guard( 'delicat_save_pins' );

	$cfg   = delicat_cfg();
	$old   = (array) $cfg['pins'];
	$rows  = ( isset( $_POST['pins'] ) && is_array( $_POST['pins'] ) ) ? wp_unslash( $_POST['pins'] ) : array();
	$errs  = array();
	$notes = array();
	$pins  = array();
	$diff  = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
			if ( ! empty( $row['delete'] ) && ! empty( $row['denom'] ) ) $diff[] = 'suppression PIN ' . (int) $row['denom'];
			continue;
		}
		$denom = (int) ( $row['denom'] ?? 0 );
		if ( $denom < 1 || $denom > 1000000 ) continue;
		if ( isset( $pins[ $denom ] ) ) {
			$errs[] = sprintf( 'Dénomination %d en double — la première a été gardée.', $denom );
			continue;
		}
		$raw = trim( (string) ( $row['pid'] ?? '' ) );
		$pid = (int) preg_replace( '/[^0-9]/', '', $raw );
		if ( $pid && ! delicat_valid_pid( $pid ) ) {
			$errs[] = sprintf( '%d 💎 : #%d n\'est ni un produit ni une variation publiée — champ laissé vide (résolution par slug).', $denom, $pid );
			$pid    = 0;
		}
		$pins[ $denom ] = $pid;
		if ( (int) ( $old[ $denom ] ?? 0 ) !== $pid ) $diff[] = sprintf( '%d💎 : #%d→#%d', $denom, (int) ( $old[ $denom ] ?? 0 ), $pid );
	}

	/* new row */
	$np = ( isset( $_POST['newpin'] ) && is_array( $_POST['newpin'] ) ) ? wp_unslash( $_POST['newpin'] ) : array();
	$nd = (int) ( $np['denom'] ?? 0 );
	if ( $nd > 0 && $nd <= 1000000 ) {
		if ( isset( $pins[ $nd ] ) ) {
			$errs[] = sprintf( 'Dénomination %d déjà présente.', $nd );
		} else {
			$pid = (int) preg_replace( '/[^0-9]/', '', (string) ( $np['pid'] ?? '' ) );
			if ( $pid && ! delicat_valid_pid( $pid ) ) {
				$errs[] = sprintf( 'Nouveau PIN %d 💎 : produit #%d introuvable — ajouté sans ID.', $nd, $pid );
				$pid = 0;
			}
			$pins[ $nd ] = $pid;
			$notes[]     = sprintf( 'PIN %d 💎 ajouté.', $nd );
			$diff[]      = sprintf( 'ajout PIN %d💎 #%d', $nd, $pid );
		}
	}

	if ( ! $pins ) {
		delicat_back( 'produits', array( 'Refusé : gardez au moins une dénomination PIN.' ) );
	}
	ksort( $pins );

	/* leaderboard extras + slug matching */
	list( $extra, $badextra ) = delicat_parse_ids( isset( $_POST['extra_pins'] ) ? wp_unslash( $_POST['extra_pins'] ) : '' );
	foreach ( $badextra as $b ) $errs[] = sprintf( 'Produit supplémentaire #%d inexistant — ignoré.', $b );

	$pattern = strtolower( sanitize_text_field( wp_unslash( $_POST['slug_pattern'] ?? '' ) ) );
	$pattern = preg_replace( '/[^a-z0-9\-%]/', '', $pattern );
	if ( false === strpos( $pattern, '%d' ) ) {
		if ( '' !== $pattern ) $errs[] = 'Le motif de slug doit contenir %d — ancienne valeur conservée.';
		$pattern = (string) $cfg['slug_pattern'];
	}

	$cfg['pins']         = $pins;
	$cfg['extra_pins']   = $extra;
	$cfg['match_slug']   = empty( $_POST['match_slug'] ) ? 0 : 1;
	$cfg['slug_pattern'] = $pattern;

	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Produits : ' . implode( ' · ', $diff ) );
	$notes[] = 'Produits enregistrés.';
	delicat_back( 'produits', $errs, $notes );
} );

/* ---- Settings: rules, prize, statuses, texts ---- */
add_action( 'admin_post_delicat_save_general', function () {
	delicat_guard( 'delicat_save_general' );

	$cfg   = delicat_cfg();
	$in    = wp_unslash( $_POST );
	$scope = ( $in['settings_scope'] ?? '' ) === 'board' ? 'board' : 'rewards';
	$preserve = 'board' === $scope ? array( 'monthly_cap' ) : array( 'prize', 'prize2', 'prize3', 'reset_day', 'reset_hour', 'board_size', 'mask_names' );
	foreach ( $preserve as $field ) $in[ $field ] = $cfg[ $field ];
	$errs  = array();
	$diff  = array();

	$method = 'wallet'; // reward claims credit TeraWallet
	if ( $method !== $cfg['reward_method'] ) $diff[] = 'méthode:' . $method;
	$cfg['reward_method'] = $method;

	/* v3 program contract: eligible purchases must be completed. */
	if ( (array) ( $cfg['statuses'] ?? array() ) !== array( 'completed' ) ) $diff[] = 'statuts:completed';
	$cfg['statuses'] = array( 'completed' );
	$cfg['strict_completed'] = 1;

	/* Security switches */
	$paid  = 1; // approved payment is mandatory
	$refnd = 1; // refund protection is mandatory
	$deliv = empty( $in['require_deliv'] ) ? 0 : 1;
	$pub   = empty( $in['board_public'] )  ? 0 : 1;
	if ( $pub )     $errs[] = '⚠️ Le classement est désormais visible sans connexion.';
	foreach ( array( 'require_paid' => $paid, 'ignore_refund' => $refnd, 'require_deliv' => $deliv, 'board_public' => $pub ) as $k => $v ) {
		if ( (int) ( $cfg[ $k ] ?? 0 ) !== $v ) $diff[] = $k . ':' . ( $v ? 'oui' : 'non' );
		$cfg[ $k ] = $v;
	}

	/* Test mode + client notifications */
	$dry = empty( $in['dry_run'] ) ? 0 : 1;
	if ( (int) ( $cfg['dry_run'] ?? 0 ) !== $dry ) $diff[] = 'mode test:' . ( $dry ? 'ON' : 'OFF' );
	if ( $dry ) $errs[] = '🧪 MODE TEST ACTIF : les clients voient leur progression mais aucune récompense n\'est livrée.';
	$cfg['dry_run'] = $dry;

	$notify = empty( $in['notify_client'] ) ? 0 : 1;
	if ( (int) ( $cfg['notify_client'] ?? 0 ) !== $notify ) $diff[] = 'email client:' . ( $notify ? 'oui' : 'non' );
	$cfg['notify_client'] = $notify;

	foreach ( array( 'notify_body', 'notify_winner' ) as $f ) {
		if ( isset( $in[ $f ] ) ) {
			$v = mb_substr( sanitize_textarea_field( (string) $in[ $f ] ), 0, DELICAT_MSG_LEN );
			if ( '' !== $v ) $cfg[ $f ] = $v;
		}
	}

	$prize = max( 1, min( DELICAT_MAX_REWARD, (int) ( $in['prize'] ?? 572 ) ) );
	if ( 'order' === $method && ! delicat_reward_plan( $prize ) ) {
		$errs[] = sprintf( 'Prix %d 💎 non composable avec vos PINs (%s) — la livraison auto échouera.',
			$prize, implode( ', ', delicat_ff_denoms() ) );
	}
	if ( $prize !== (int) $cfg['prize'] ) $diff[] = sprintf( 'prix %d→%d', (int) $cfg['prize'], $prize );
	$cfg['prize'] = $prize;
	update_option( 'delicat_meilleur_prize', $prize ); // keep the legacy option in sync

	foreach ( array( 2 => 'prize2', 3 => 'prize3' ) as $rk => $pk ) {
		$pv = max( 0, min( DELICAT_MAX_REWARD, (int) ( $in[ $pk ] ?? 0 ) ) );
		if ( $pv > 0 && 'order' === $method && ! delicat_reward_plan( $pv ) ) {
			$errs[] = sprintf( 'Prix %de place %d 💎 non composable avec vos PINs (%s) — la livraison auto échouera.',
				$rk, $pv, implode( ', ', delicat_ff_denoms() ) );
		}
		if ( $pv !== (int) $cfg[ $pk ] ) $diff[] = sprintf( 'prix #%d %d→%d', $rk, (int) $cfg[ $pk ], $pv );
		$cfg[ $pk ] = $pv;
	}

	$cap = max( 0, min( DELICAT_MAX_MONTH_CAP, (int) ( $in['monthly_cap'] ?? 0 ) ) );
	if ( $cap !== (int) $cfg['monthly_cap'] ) $diff[] = sprintf( 'plafond mensuel %d→%d', (int) $cfg['monthly_cap'], $cap );
	$cfg['monthly_cap'] = $cap;

	$cfg['reset_day']  = max( 0, min( 6,  (int) ( $in['reset_day'] ?? 0 ) ) );
	$cfg['reset_hour'] = max( 0, min( 23, (int) ( $in['reset_hour'] ?? 20 ) ) );
	$cfg['board_size'] = max( 1, min( 50, (int) ( $in['board_size'] ?? 10 ) ) );
	if ( 'board' === $scope ) { // 3.4.2 (RWD-05): from the next weekly reset only
		$min_order = max( 0, min( 100000, (int) ( $in['board_min_order'] ?? 0 ) ) );
		if ( abs( delicat_board_min_order( delicat_next_week_start() ) - $min_order ) > 0.001 ) {
			$from = delicat_board_min_plan( $min_order );
			$diff[] = sprintf( 'minimum classement %d dès le %s', $min_order, wp_date( 'd/m/Y H:i', $from ) );
			$errs[] = sprintf( 'Montant minimum pour le classement : %s à partir du %s. La semaine en cours garde sa règle.', $min_order > 0 ? $min_order . ' ' . $cfg['currency'] : 'aucun', wp_date( 'd/m/Y H:i', $from ) );
		}
	}
	$cfg['mask_names'] = $pub ? 1 : ( empty( $in['mask_names'] ) ? 0 : 1 );

	$wa = esc_url_raw( trim( (string) ( $in['wa'] ?? '' ) ), array( 'http', 'https' ) );
	if ( '' === $wa && '' !== trim( (string) ( $in['wa'] ?? '' ) ) ) {
		$errs[] = 'Lien WhatsApp invalide (http/https requis) — ancienne valeur conservée.';
	} else {
		$cfg['wa'] = $wa;
	}

	foreach ( array( 'page_title', 'program_name', 't_spend', 't_reward', 't_progress', 'note', 'unit', 'currency', 'badge_suffix', 'btn_prefix', 'btn_suffix', 'btn_locked', 'btn_dry', 'notify_subject', 'board_kicker', 'board_title', 'board_games', 'board_reset_label', 'board_reward_label', 'board_prize_suffix', 'board_reward_sub', 'board_you_label', 'board_login_text', 'board_heading', 'board_hidden_text', 'board_empty_text', 'board_first_text', 'board_rank_text', 'board_gap_text', 'board_start_text', 'board_footer_1', 'board_footer_2' ) as $f ) {
		if ( isset( $in[ $f ] ) ) {
			$v = delicat_txt( $in[ $f ], 'note' === $f ? 160 : DELICAT_TEXT_LEN );
			if ( '' !== $v ) $cfg[ $f ] = $v;
		}
	}

	if ( isset( $in['board_footer_url'] ) ) {
		$board_url = esc_url_raw( trim( (string) $in['board_footer_url'] ), array( 'http', 'https' ) );
		if ( $board_url ) $cfg['board_footer_url'] = $board_url;
	}

	delicat_cfg_save( $cfg );
	if ( $diff ) delicat_audit_add( 'Réglages : ' . implode( ' · ', $diff ) );
	delicat_back( 'board' === $scope ? 'meilleur' : 'reglages', $errs, array( 'Réglages enregistrés.' ) );
} );

/* ---- Program on/off switches ---- */
add_action( 'admin_post_delicat_toggle', function () {
	delicat_guard( 'delicat_toggle' );
	$which = sanitize_key( wp_unslash( $_POST['which'] ?? '' ) );
	$map   = array(
		'rewards'   => array( 'delicat_rewards_enabled',  'delicat_rewards_on',  'Rewards Program' ),
		'meilleur'  => array( 'delicat_meilleur_enabled', 'delicat_meilleur_on', 'Meilleur Client' ),
		'autoaward' => array( 'delicat_auto_award',       'delicat_auto_award_on','Préparation des récompenses à réclamer' ),
	);
	if ( isset( $map[ $which ] ) ) {
		list( $opt, $fn, $label ) = $map[ $which ];
		$now = call_user_func( $fn ) ? '0' : '1';
		update_option( $opt, $now );
		delete_transient( 'delicat_leaderboard' );
		delicat_audit_add( $label . ' : ' . ( '1' === $now ? 'ACTIVÉ' : 'EN PAUSE' ) );
	}
	delicat_back( 'rewards' === $which ? 'reglages' : 'meilleur' );
} );

/* ---- Clear the integrity alerts (after the admin has checked the accounts) ---- */
add_action( 'admin_post_delicat_clear_tamper', function () {
	delicat_guard( 'delicat_clear_tamper' );
	delete_option( 'delicat_tamper' );
	delicat_audit_add( 'Alertes d\'intégrité effacées après vérification.' );
	delicat_back( 'diagnostics', array(), array( 'Alertes effacées.' ) );
} );

/** Tier claims per reward order of $month, in any status except cancelled or failed. */
function delicat_reward_order_claims_any( $user_id, $month ) {
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) return array();
	list( $start, $end ) = delicat_month_bounds( $month );
	$statuses = array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ) ) );
	$args  = array( 'customer_id' => (int) $user_id, 'status' => $statuses, 'date_created' => ( $start - DAY_IN_SECONDS ) . '...' . ( $end + DAY_IN_SECONDS ), 'orderby' => 'date', 'order' => 'ASC' );
	$tiers = array_merge( delicat_tier_history(), delicat_all_tiers() ); $out = array();
	delicat_orders_each( $args, function ( $o ) use ( &$out, $month, $tiers ) {
		$tag  = sanitize_key( (string) $o->get_meta( '_delicat_reward' ) );
		$kind = sanitize_key( (string) $o->get_meta( '_delicat_reward_kind' ) );
		if ( '' === $tag || 'weekly' === $kind || 0 === strpos( $tag, 'weekly_' ) ) return; // the podium does not use the tier ledger
		if ( 'tier' !== $kind && ! isset( $tiers[ $tag ] ) ) return;
		$m = (string) $o->get_meta( '_delicat_reward_month' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $m ) ) { $d = $o->get_date_created(); $m = $d ? delicat_month_key( $d->getTimestamp() ) : ''; }
		if ( $m === $month ) $out[ $tag ] = ( $out[ $tag ] ?? 0 ) + 1;
	}, 100, 2000 );
	return $out;
}

/**
 * Rebuilds and re-signs the ledgers of $month that no longer verify (salts rotated, host
 * move). Claims are never lowered: each tier keeps the highest of the stored value, the
 * legacy _delicat_claimed_ counters and the month's reward orders in any status except
 * cancelled or failed (a reward order stuck in processing still used its claim). Carried
 * debt is never lowered either. Stops after $budget seconds; run it again for the rest.
 */
function delicat_reseal_month( $month, $budget = 20 ) {
	$started = time(); $fixed = 0; $left = 0; $ids = array();
	foreach ( array( delicat_ledger_key( $month ), delicat_ledger_migration_key( $month ) ) as $meta_key ) {
		foreach ( get_users( array( 'meta_key' => $meta_key, 'fields' => 'ID', 'number' => -1 ) ) as $id ) $ids[ (int) $id ] = true;
	}
	$current   = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$tier_keys = array_keys( array_merge( delicat_tier_history(), delicat_all_tiers() ) );
	foreach ( array_keys( $ids ) as $uid ) {
		$peek = delicat_ledger_peek( $uid, $month );
		if ( $peek['exists'] ? $peek['ok'] : ! get_user_meta( $uid, delicat_ledger_migration_key( $month ), true ) ) continue;
		if ( time() - $started >= $budget ) { $left++; continue; }
		$lock = 'delicat_claim_' . $uid . '_' . $current;
		if ( ! delicat_lock( $lock, 2 ) ) { $left++; continue; }
		try {
			$claims = $peek['claims'];
			foreach ( array_unique( array_merge( $tier_keys, array_keys( $claims ) ) ) as $k ) {
				$n = (int) get_user_meta( $uid, "_delicat_claimed_{$month}_{$k}", true );
				if ( $n > ( $claims[ $k ] ?? 0 ) ) $claims[ $k ] = $n;
			}
			foreach ( delicat_reward_order_claims_any( $uid, $month ) as $k => $n ) if ( $n > ( $claims[ $k ] ?? 0 ) ) $claims[ $k ] = $n;
			$debt = $peek['debt'];
			foreach ( delicat_debt_carry( $uid, $month ) as $k => $n ) if ( $n > ( $debt[ $k ] ?? 0 ) ) $debt[ $k ] = $n;
			delicat_ledger_save( $uid, $month, $claims, $debt );
			delete_user_meta( $uid, '_delicat_integrity_alert_' . sanitize_key( $month ) );
			if ( $month < $current ) delicat_debt_forward( $uid, $month, $current );
			delicat_flush_user( $uid );
			$fixed++;
		} catch ( \Throwable $e ) {
			$left++;
			delicat_log( 'error', 'Ledger re-seal failed for one customer.', array( 'user_id' => (int) $uid, 'month' => $month ) );
		} finally { delicat_unlock( $lock ); }
	}
	if ( ! $left ) update_option( 'delicat_hmac_fingerprint', delicat_key_fingerprint( delicat_secret() ), false );
	return array( 'fixed' => $fixed, 'left' => $left );
}

add_action( 'admin_post_delicat_reseal', function () {
	delicat_guard( 'delicat_reseal' );
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) wp_die( 'POST requis.' );
	$current = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$month   = sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
	if ( ! in_array( $month, array( $current, delicat_month_shift( $current, -1 ) ), true ) ) $month = $current;
	$r = delicat_reseal_month( $month );
	delicat_audit_add( sprintf( 'Registres %s re-scellés : %d compte(s) réparé(s), %d restant(s).', $month, $r['fixed'], $r['left'] ) );
	$errors = $r['left'] ? array( sprintf( '%d compte(s) restant(s) : cliquez de nouveau sur « Re-sceller les registres du mois ».', $r['left'] ) ) : array();
	delicat_back( 'diagnostics', $errors, array( sprintf( '%s : %d registre(s) re-scellé(s). Aucune réclamation n’a été baissée.', $month, $r['fixed'] ) ) );
} );

/* ---- Safe deployment preset: preserves all IDs/tier rules, only hardens runtime and enables dry-run. ---- */
add_action( 'admin_post_delicat_safe_setup', function () {
	delicat_guard( 'delicat_safe_setup' );
	$cfg = delicat_cfg();
	$cfg['reward_method']    = 'wallet';
	$cfg['statuses']         = array( 'completed' );
	$cfg['strict_completed'] = 1;
	$cfg['require_paid']     = 1;
	$cfg['ignore_refund']    = 1;
	$cfg['dry_run']          = 1;
	$cfg['board_public']     = 0;
	delicat_cfg_save( $cfg );
	delicat_audit_add( 'Assistant installation : mode test sécurisé activé (completed + paid + refunds + HTG Wallet).' );
	delicat_back( 'installation', array(), array( 'Mode test sécurisé activé. Aucun PIN ni prix hebdomadaire ne sera livré tant que le mode test reste actif.' ) );
} );

/* ---- Restore defaults (config only — no order, claim or code is touched) ---- */
add_action( 'admin_post_delicat_reset', function () {
	delicat_guard( 'delicat_reset' );
	delicat_cfg_save( delicat_defaults() );
	delicat_audit_add( 'Configuration réinitialisée aux valeurs par défaut.' );
	delicat_back( 'reglages', array(), array( 'Configuration réinitialisée. Vérifiez les IDs produits dans l\'onglet Produits.' ) );
} );

/* =========================================================================
   ADMIN — PAGE
   ========================================================================= */
function delicat_admin_page() {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}
	$tabs = array(
		'installation'=> 'Installation',
		'tiers'       => 'Rewards Program — Tiers',
		'meilleur' => 'Meilleur client',
		'parrainage'  => 'Parrainage',
		'produits'    => 'Produits',
		'reglages'    => 'Rewards — Réglages',
		'branding'     => 'Nom & Logo',
		'rapports'    => 'Rapports',
		'diagnostics' => 'Diagnostics',
		'journal'     => 'Journal',
	);
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'installation';
	if ( ! isset( $tabs[ $tab ] ) ) $tab = 'installation';

	echo '<div class="wrap"><h1>Delicat Rewards</h1>';
	delicat_notice_show();

	echo '<h2 class="nav-tab-wrapper" style="margin-bottom:18px">';
	foreach ( $tabs as $k => $label ) {
		$url = add_query_arg( array( 'page' => 'delicat-rewards', 'tab' => $k ), admin_url( 'admin.php' ) );
		echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</h2>';

	echo '<style>
	.dlcadm table.form{border-collapse:collapse;width:100%;max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:hidden}
	.dlcadm table.form th{background:#f6f7f7;text-align:left;padding:10px 12px;font-size:12px;letter-spacing:.4px;text-transform:uppercase;color:#50575e;border-bottom:1px solid #dcdcde}
	.dlcadm table.form td{padding:12px;border-bottom:1px solid #f0f0f1;vertical-align:top}
	.dlcadm input[type=number]{width:88px}
	.dlcadm input.ids{width:150px}
	.dlcadm input.txt{width:190px}
	.dlcadm .hint{color:#646970;font-size:12px;line-height:1.5}
	.dlcadm .off{opacity:.55}
	.dlcadm .box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;max-width:1100px;margin:0 0 18px}
	.dlcadm .box h3{margin:0 0 6px}
	.dlcadm .danger{border-color:#f3c2c2;background:#fff6f6}
	.dlcadm .addrow{background:#f6fbf7}
	</style><div class="dlcadm">';

	if ( 'installation' === $tab )     delicat_tab_setup();
	elseif ( 'meilleur' === $tab ) { delicat_tab_competition(); delicat_tab_settings( true ); }
	elseif ( 'tiers' === $tab )        delicat_tab_tiers();
	elseif ( 'parrainage' === $tab )   delicat_tab_referral();
	elseif ( 'produits' === $tab )     delicat_tab_products();
	elseif ( 'reglages' === $tab )     delicat_tab_settings();
	elseif ( 'branding' === $tab )      delicat_tab_branding();
	elseif ( 'rapports' === $tab )     delicat_tab_reports();
	elseif ( 'diagnostics' === $tab )  delicat_tab_diagnostics();
	elseif ( 'journal' === $tab )      delicat_tab_audit();

	echo '</div></div>';
}


/* ---- TAB: INSTALLATION / READINESS ---- */
function delicat_tab_setup() {
	$cfg      = delicat_cfg();
	$problems = delicat_config_problems();
	$tiers    = delicat_tiers();
	$pins     = (array) ( $cfg['pins'] ?? array() );
	$valid_pins = 0;
	foreach ( $pins as $denom => $pid ) if ( delicat_pid_by_diamonds( (int) $denom ) ) $valid_pins++;
	$mapped_tiers = 0;
	foreach ( $tiers as $t ) if ( ! empty( $t['product_ids'] ) ) $mapped_tiers++;
	$dry = ! empty( $cfg['dry_run'] );
	$program = delicat_rewards_on();

	$u = function ( $tab ) { return admin_url( 'admin.php?page=delicat-rewards&tab=' . $tab ); };
	$ok = '<span style="color:#137333;font-weight:700">✅ OK</span>';
	$no = '<span style="color:#b32d2e;font-weight:700">⛔ À corriger</span>';
	$warn = '<span style="color:#996800;font-weight:700">⚠️ Vérifier</span>';

	echo '<div class="box"><h2 style="margin-top:0">Assistant d’installation — Omni Free Fire Rewards</h2>';
	echo '<p>Objectif : <b>tout achat Free Fire éligible</b> (PIN ou top-up direct, peu importe le serveur) progresse après <b>paiement approuvé + commande WooCommerce terminée</b>. La récompense est <b>créditée en HTG dans Delicat Wallet après réclamation</b>.</p>';
	echo '<p class="hint">Cet assistant ne remplace pas WooCommerce et ne modifie jamais votre API direct-top-up. WooCommerce reste la source de vérité.</p></div>';

	echo '<div class="box"><h3>État actuel</h3><table class="widefat striped"><tbody>';
	echo '<tr><td style="width:330px"><b>WooCommerce</b></td><td>' . ( class_exists( 'WooCommerce' ) ? $ok : $no ) . '</td></tr>';
	echo '<tr><td><b>Produits de référence résolus</b></td><td>' . ( $valid_pins === count( $pins ) && $valid_pins > 0 ? $ok : $warn ) . ' &nbsp; ' . (int) $valid_pins . '/' . (int) count( $pins ) . '</td></tr>';
	echo '<tr><td><b>Tiers actifs avec produits éligibles</b></td><td>' . ( $mapped_tiers === count( $tiers ) && $mapped_tiers > 0 ? $ok : $warn ) . ' &nbsp; ' . (int) $mapped_tiers . '/' . (int) count( $tiers ) . '</td></tr>';
	echo '<tr><td><b>Diagnostics</b></td><td>' . ( ! $problems ? $ok : $no . ' &nbsp; ' . count( $problems ) . ' problème(s)' ) . '</td></tr>';
	echo '<tr><td><b>Mode test</b></td><td>' . ( $dry ? '<span style="color:#996800;font-weight:700">🧪 ACTIF — aucune récompense livrée</span>' : '<span style="color:#137333;font-weight:700">RÉEL — récompenses autorisées</span>' ) . '</td></tr>';
	echo '<tr><td><b>Rewards Program</b></td><td>' . ( $program ? '<span style="color:#137333;font-weight:700">ACTIF</span>' : '<span style="color:#646970;font-weight:700">EN PAUSE</span>' ) . '</td></tr>';
	echo '</tbody></table></div>';

	echo '<div class="box"><h3>1. Préparer un test sécurisé</h3><p>Ce bouton <b>ne change aucun ID produit, aucun objectif et aucune progression client</b>. Il force seulement : Woo <code>completed</code>, paiement approuvé, remboursements déduits, récompense HTG dans Delicat Wallet, classement privé et <b>Mode test</b>.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_safe_setup' );
	echo '<input type="hidden" name="action" value="delicat_safe_setup"><button class="button button-primary button-large">🧪 Activer la configuration de test sécurisée</button></form></div>';

	echo '<div class="box"><h3>2. Configurer les montants HTG des récompenses</h3><p>Ouvrez <a href="' . esc_url( $u( 'produits' ) ) . '"><b>Produits</b></a>. Pour chaque dénomination (110, 341, 572, 1160, 2180…), indiquez le <b>produit ou la variation PIN exacte</b> dont le prix HTG servira de référence. Vous pouvez aussi fixer les montants dans Réglages → Récompenses en argent.</p>';
	echo '<p class="hint">Exemple : 110 💎 → variation WooCommerce #1234 correspondant au PIN 110. Aucun code fournisseur ne sera acheté ou livré pour les nouvelles récompenses.</p></div>';

	echo '<div class="box"><h3>3. Mapper tous les achats Free Fire qui gagnent de la progression</h3><p>Ouvrez <a href="' . esc_url( $u( 'tiers' ) ) . '"><b>Tiers</b></a>. Pour chaque montant, ajoutez dans <b>Produits éligibles</b> tous les IDs du même pack : PIN, direct top-up, LATAM, Europe, Brésil, USA, ancien produit, etc.</p>';
	echo '<p><b>Important pour les variations :</b> si le produit parent contient plusieurs montants de diamants, mappez les <b>IDs des variations exactes</b>. Utilisez le parent seulement si toutes ses variations représentent le même montant.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Pack</th><th>Objectif conseillé</th><th>Récompense</th></tr></thead><tbody>';
	$journey = array( 110 => 25, 341 => 20, 572 => 15, 1160 => 12, 2180 => 10 );
	foreach ( $journey as $d => $g ) echo '<tr><td>' . (int) $d . ' 💎</td><td>' . (int) $g . ' achats terminés</td><td>' . (int) $d . ' 💎 gratuit en PIN</td></tr>';
	echo '</tbody></table></div>';

	echo '<div class="box"><h3>4. Installer les pages clients</h3><p>Ajoutez ces shortcodes dans vos pages WordPress :</p>';
	echo '<p><code>[delicat_rewards]</code> — progression mensuelle + réclamation sécurisée.</p>';
	echo '<p><code>[delicat_meilleur_client]</code> — classement hebdomadaire / podium.</p>';
	echo '<p class="hint">Vous pouvez utiliser Gutenberg, Elementor ou un bloc Shortcode. Le client doit être connecté pour voir ses données privées.</p></div>';

	echo '<div class="box"><h3>5. Tester avant la mise en production</h3><ol style="line-height:1.8">';
	echo '<li>Gardez <b>Mode test actif</b>.</li><li>Passez une vraie commande test avec un compte client.</li><li>Validez le paiement et laissez votre système de livraison/direct top-up terminer la commande WooCommerce.</li><li>Vérifiez que la progression augmente uniquement quand l’ordre devient <code>completed</code>.</li><li>Testez PIN et direct top-up sur chaque serveur/variation mappé.</li><li>Ouvrez <a href="' . esc_url( $u( 'diagnostics' ) ) . '"><b>Diagnostics</b></a> et corrigez toutes les alertes rouges.</li></ol></div>';

	echo '<div class="box"><h3>6. Passer en réel</h3><p>Quand les diagnostics sont propres et les tests corrects : allez dans <a href="' . esc_url( $u( 'reglages' ) ) . '"><b>Réglages</b></a>, désactivez <b>Mode test</b>, activez <b>Rewards Program</b>, puis activez éventuellement <b>Meilleur Client</b> et sa livraison automatique après avoir testé le podium.</p>';
	if ( $problems ) echo '<p style="color:#b32d2e"><b>Ne passez pas en réel maintenant :</b> ' . (int) count( $problems ) . ' problème(s) de configuration restent détectés.</p>';
	else echo '<p style="color:#137333"><b>Diagnostics : aucune erreur de configuration détectée.</b></p>';
	echo '</div>';
}

/* ---- TAB: NOM & LOGO ---- */
function delicat_tab_branding() {
	$cfg = delicat_cfg();
	$logo_id = absint( $cfg['brand_logo_id'] ?? 0 );
	$logo = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	wp_enqueue_media();
	echo '<div class="box"><h3>Identité visuelle du programme</h3><p class="hint">Le nom et le logo apparaissent en haut de la carte Rewards. Le fichier est choisi dans la Médiathèque WordPress et son ID est validé côté serveur.</p></div>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_branding' );
	echo '<input type="hidden" name="action" value="delicat_save_branding">';
	echo '<div class="box"><table class="widefat" style="border:0"><tbody>';
	echo '<tr><td style="width:260px"><b>Nom de la boutique</b></td><td><input type="text" class="regular-text" maxlength="' . DELICAT_TEXT_LEN . '" name="brand_name" value="' . esc_attr( (string) ( $cfg['brand_name'] ?? '' ) ) . '"></td></tr>';
	echo '<tr><td><b>Logo</b></td><td><input type="hidden" id="delicat_brand_logo_id" name="brand_logo_id" value="' . $logo_id . '">';
	echo '<div id="delicat_brand_preview" style="width:220px;min-height:90px;border:1px dashed #b8b8b8;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#fafafa;margin-bottom:10px;padding:10px">';
	if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" alt="" style="max-width:200px;max-height:110px;object-fit:contain">';
	else echo '<span class="hint">Aucun logo sélectionné</span>';
	echo '</div><button type="button" class="button" id="delicat_choose_logo">Choisir / remplacer</button> <button type="button" class="button" id="delicat_remove_logo">Retirer</button></td></tr>';
	echo '</tbody></table></div><p><button class="button button-primary button-large">Enregistrer le nom et le logo</button></p></form>';
	echo <<<'JS'
<script>
(function($){
 var frame;
 $('#delicat_choose_logo').on('click',function(e){e.preventDefault(); if(frame){frame.open();return;} frame=wp.media({title:'Choisir le logo Rewards',button:{text:'Utiliser ce logo'},multiple:false,library:{type:'image'}}); frame.on('select',function(){var a=frame.state().get('selection').first().toJSON(); $('#delicat_brand_logo_id').val(a.id); $('#delicat_brand_preview').html('<img src="'+(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url)+'" alt="" style="max-width:200px;max-height:110px;object-fit:contain">');}); frame.open();});
 $('#delicat_remove_logo').on('click',function(){ $('#delicat_brand_logo_id').val('0'); $('#delicat_brand_preview').html('<span class="hint">Aucun logo sélectionné</span>'); });
})(jQuery);
</script>
JS;
}

/* ---- TAB: TIERS ---- */
function delicat_tab_tiers() {
	delicat_products_datalist();
	$tiers = delicat_all_tiers();

	echo '<div class="box"><h3>Free Fire — achats éligibles et récompenses HTG</h3>'
		. '<p class="hint"><b>Pack 💎</b> : le montant Free Fire suivi par ce palier (110, 341, 572…). Le produit PIN correspondant dans l\'onglet Produits est <b>toujours compté automatiquement</b>.<br>'
		. '<b>Produits éligibles</b> : ajoutez tous les autres IDs WooCommerce représentant le même pack : top-up direct, autres serveurs/régions, anciens produits ou variations. Séparez-les par des virgules.<br>'
		. '<b>Produit variable / plusieurs serveurs :</b> si un parent contient plusieurs montants de diamants, mettez les IDs des <b>variations exactes</b>. N\'utilisez le parent que si toutes ses variations représentent le même pack.<br>'
		. '<b>Règle v3 :</b> progression uniquement après paiement confirmé + commande WooCommerce <code>completed</code>. La récompense est versée en HTG dans Delicat Wallet après réclamation.<br>'
		. '<b>Limite/mois</b> : 0 = illimité. La clé du tier est permanente : renommer le badge ne remet jamais les réclamations à zéro.</p></div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_tiers' );
	echo '<input type="hidden" name="action" value="delicat_save_tiers">';

	echo '<p class="hint">Les IDs des fournisseurs précédents sont conservés pour le même pack et la même clé de tier, même si leurs produits sont supprimés. Gardez la clé du tier lors du changement de fournisseur. Pour récupérer un ancien pack sans nom explicite (Free Fire + nombre de diamants), ajoutez son ID historique.</p>';
	echo '<table class="form"><thead><tr>'
		. '<th style="width:52px">Actif</th><th>Badge</th><th>Titre affiché</th><th>Pack 💎</th><th>Produits éligibles (direct/PIN/serveurs)</th>'
		. '<th>Objectif</th><th>Récompense PIN</th><th>Limite/mois</th><th style="width:60px">Ordre</th><th style="width:70px">Suppr.</th>'
		. '</tr></thead><tbody>';

	foreach ( $tiers as $key => $t ) {
		$ids   = implode( ', ', array_map( 'intval', (array) ( $t['ids'] ?? array() ) ) );
		$plan  = delicat_reward_plan( (int) ( $t['reward'] ?? 0 ) );
		$compo = $plan ? implode( ' + ', array_map( function ( $l ) { return $l['qty'] . '× PIN ' . $l['denom']; }, $plan ) ) : '⛔ non composable';
		$pinpid = delicat_pid_by_diamonds( (int) ( $t['pin'] ?? 0 ) );

		echo '<tr' . ( empty( $t['enabled'] ) ? ' class="off"' : '' ) . '>';
		echo '<td><input type="checkbox" name="tiers[' . esc_attr( $key ) . '][enabled]" value="1"' . checked( ! empty( $t['enabled'] ), true, false ) . '></td>';
		echo '<td><input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][label]" value="' . esc_attr( $t['label'] ?? '' ) . '" maxlength="' . DELICAT_TEXT_LEN . '"><div class="hint">clé : <code>' . esc_html( $key ) . '</code></div></td>';
		echo '<td><input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][title]" value="' . esc_attr( $t['title'] ?? '' ) . '" placeholder="Free Fire"><br>'
			. '<input class="txt" type="text" name="tiers[' . esc_attr( $key ) . '][sub]" value="' . esc_attr( $t['sub'] ?? '' ) . '" placeholder="110 Diamants" style="margin-top:6px"></td>';
		echo '<td><input type="number" min="1" max="1000000" name="tiers[' . esc_attr( $key ) . '][pin]" value="' . (int) ( $t['pin'] ?? 0 ) . '"> 💎'
			. '<div class="hint">PIN auto : ' . ( $pinpid ? delicat_pid_hint( $pinpid ) : '<b style="color:#b32d2e">⛔ non mappé</b>' ) . '</div></td>';
		echo '<td><input class="ids" type="text" name="tiers[' . esc_attr( $key ) . '][ids]" value="' . esc_attr( $ids ) . '" list="dlc-products" placeholder="ex. 197, 289, 845">'
			. '<div class="hint">' . ( '' !== $ids ? implode( '<br>', array_map( 'delicat_pid_hint', array_map( 'intval', (array) $t['ids'] ) ) ) : 'Aucun ID supplémentaire — les anciens fournisseurs restent comptabilisés.' ) . '</div></td>';
		echo '<td><input type="number" min="1" max="' . DELICAT_MAX_GOAL . '" name="tiers[' . esc_attr( $key ) . '][goal]" value="' . (int) ( $t['goal'] ?? 1 ) . '"><div class="hint">fois</div></td>';
		echo '<td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="tiers[' . esc_attr( $key ) . '][reward]" value="' . (int) ( $t['reward'] ?? 0 ) . '"><div class="hint">' . esc_html( $compo ) . '</div></td>';
		echo '<td><input type="number" min="0" max="999" name="tiers[' . esc_attr( $key ) . '][max_claims]" value="' . (int) ( $t['max_claims'] ?? 0 ) . '"><div class="hint">0 = illimité</div></td>';
		echo '<td><input type="number" min="0" max="99" name="tiers[' . esc_attr( $key ) . '][order]" value="' . (int) ( $t['order'] ?? 0 ) . '"></td>';
		echo '<td><label class="hint"><input type="checkbox" name="tiers[' . esc_attr( $key ) . '][delete]" value="1"> retirer</label></td>';
		echo '</tr>';
	}

	echo '<tr class="addrow"><td>＋</td>'
		. '<td><input class="txt" type="text" name="newtier[label]" placeholder="Diamond" maxlength="' . DELICAT_TEXT_LEN . '"></td>'
		. '<td><input class="txt" type="text" name="newtier[title]" placeholder="Free Fire"><br><input class="txt" type="text" name="newtier[sub]" placeholder="4360 Diamants" style="margin-top:6px"></td>'
		. '<td><input type="number" min="1" max="1000000" name="newtier[pin]" placeholder="4360"></td>'
		. '<td><input class="ids" type="text" name="newtier[ids]" list="dlc-products" placeholder="direct/PIN/serveurs"></td>'
		. '<td><input type="number" min="1" max="' . DELICAT_MAX_GOAL . '" name="newtier[goal]" value="10"></td>'
		. '<td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="newtier[reward]" value="0"></td>'
		. '<td><input type="number" min="0" max="999" name="newtier[max_claims]" value="0"></td>'
		. '<td colspan="2" class="hint">Ajoutez un nouveau pack/récompense Free Fire.</td></tr>';

	echo '</tbody></table><p><button class="button button-primary button-large">Enregistrer les tiers</button></p></form>';
}

/* ---- TAB: PRODUITS ---- */
function delicat_tab_products() {
	delicat_products_datalist();
	$cfg  = delicat_cfg();
	$pins = (array) $cfg['pins'];

	echo '<div class="box"><h3>Free Fire — produits de référence et prix automatiques HTG</h3>'
		. '<p class="hint">Chaque dénomination pointe vers le produit/variation PIN utilisé pour <b>livrer les récompenses gratuites</b>. Les top-ups directs et autres serveurs se configurent dans <b>Tiers ▸ Produits éligibles</b>.<br>'
		. 'Laissez l\'ID vide pour laisser le plugin retrouver le produit par son slug (<code>' . esc_html( $cfg['slug_pattern'] ) . '</code>).<br>'
		. '<b>Produits variables :</b> pour la <u>livraison</u> des récompenses, indiquez de préférence l\'ID de la <b>variation</b> exacte à offrir. '
		. 'Si vous mettez l\'ID du produit parent, le plugin choisit automatiquement la première variation disponible en stock.<br>'
		. 'Ces dénominations servent à compter les achats et à calculer les prix HTG automatiques. Les récompenses sont versées dans Delicat Wallet ; aucun stock de PIN n’est consommé.</p></div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_pins' );
	echo '<input type="hidden" name="action" value="delicat_save_pins">';

	echo '<table class="form"><thead><tr><th style="width:130px">Diamants</th><th style="width:140px">ID produit</th><th>Produit résolu</th><th style="width:120px">Stock codes</th><th style="width:80px">Suppr.</th></tr></thead><tbody>';
	$i = 0;
	foreach ( $pins as $denom => $pid ) {
		$resolved = delicat_pid_by_diamonds( $denom );
		echo '<tr>';
		echo '<td><input type="number" min="1" max="1000000" name="pins[' . $i . '][denom]" value="' . (int) $denom . '"> 💎</td>';
		echo '<td><input class="ids" type="text" name="pins[' . $i . '][pid]" value="' . ( (int) $pid ?: '' ) . '" list="dlc-products" placeholder="auto"></td>';
		echo '<td>' . delicat_pid_hint( $resolved ) . ( ( ! $pid && $resolved ) ? ' <span class="hint">(trouvé par slug)</span>' : '' ) . '</td>';
		echo '<td>' . delicat_stock_cell( $resolved ) . '</td>';
		echo '<td><label class="hint"><input type="checkbox" name="pins[' . $i . '][delete]" value="1"> retirer</label></td>';
		echo '</tr>';
		$i++;
	}
	echo '<tr class="addrow"><td><input type="number" min="1" max="1000000" name="newpin[denom]" placeholder="4360"> 💎</td>'
		. '<td><input class="ids" type="text" name="newpin[pid]" list="dlc-products" placeholder="ID"></td>'
		. '<td colspan="3" class="hint">Ajouter une dénomination.</td></tr>';
	echo '</tbody></table>';

	$extra = implode( ', ', array_map( 'intval', (array) $cfg['extra_pins'] ) );
	echo '<div class="box" style="margin-top:18px"><h3>Free Fire — autres produits comptés seulement au classement</h3>';
	echo '<p><input type="text" class="regular-text" name="extra_pins" value="' . esc_attr( $extra ) . '" list="dlc-products" placeholder="ex. 412, 588"></p>';
	echo '<p class="hint">IDs de produits Free Fire qui comptent pour le <b>classement Meilleur Client</b> sans être un tier (autres recharges, packs…).</p>';
	echo '<p><label><input type="checkbox" name="match_slug" value="1"' . checked( ! empty( $cfg['match_slug'] ), true, false ) . '> '
		. 'Compter aussi automatiquement tout produit dont le slug suit le motif</label> '
		. '<input type="text" name="slug_pattern" value="' . esc_attr( $cfg['slug_pattern'] ) . '" style="width:220px"> <span class="hint">(<code>%d</code> = le nombre de diamants)</span></p></div>';

	echo '<p><button class="button button-primary button-large">Enregistrer les produits</button></p></form>';
}

/* ---- TAB: RÉGLAGES ---- */
function delicat_tab_settings( $board = false ) {
	delicat_wallet_admin_values();
	delicat_wallet_admin_review();
	$cfg = delicat_cfg();

	if ( ! empty( $cfg['dry_run'] ) ) {
		echo '<div class="notice notice-warning" style="margin:0 0 16px"><p><b>🧪 MODE TEST ACTIF.</b> Les clients voient leur progression, les boutons sont grisés, '
			. 'et <b>aucune récompense n\'est livrée</b> (ni bonus, ni prix hebdo). Idéal quand vous changez les objectifs en cours de mois.</p></div>';
	}

	/* switches */
	echo '<div class="box"><h3>Activation</h3>';
	if ( ! $board ) echo delicat_toggle_row( 'rewards',   'Rewards Program (bonus Free Fire)', delicat_rewards_on() );
	if ( $board ) echo delicat_toggle_row( 'meilleur',  'Meilleur Client (classement)',      delicat_meilleur_on() );
	if ( $board ) echo delicat_toggle_row( 'autoaward', 'Préparer automatiquement les récompenses hebdomadaires à réclamer (5 jours)', delicat_auto_award_on() );
	echo '</div>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_save_general' );
	echo '<input type="hidden" name="action" value="delicat_save_general">';

	echo '<input type="hidden" name="settings_scope" value="' . ( $board ? 'board' : 'rewards' ) . '">';
	/* rules */
	echo '<div class="box"><h3>Règles communes aux deux programmes</h3><table class="widefat" style="border:0"><tbody>';

	echo '<tr><td style="width:280px"><b>Livraison des récompenses</b></td><td>';
	echo '<b>Crédit HTG dans Delicat Wallet</b> — versement après réclamation. <span class="hint">Bonus : avant la fin du mois. Meilleur Client : sous 5 jours. Les achats PIN et top-up direct restent éligibles.</span></td></tr>';

	echo '<tr><td><b>Commandes comptées</b></td><td><b>Terminée (completed) uniquement ✅</b><div class="hint">Le paiement doit aussi être confirmé. Pour un top-up direct, le statut completed est la preuve que la recharge a été reçue. Remboursements/annulations retirent la progression.</div></td></tr>';

	echo '<tr><td><b>Sécurité des achats</b></td><td>';
	echo '<div style="margin-bottom:6px"><b>✅ Paiement approuvé obligatoire</b> <span class="hint">— date_paid + WooCommerce paid.</span></div>';
	echo '<div style="margin-bottom:6px"><b>✅ Remboursements déduits automatiquement</b> <span class="hint">— une quantité remboursée ne garde pas sa progression; après une récompense, les achats futurs compensent d\'abord le manque.</span></div>';
	echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="require_deliv" value="1"' . checked( ! empty( $cfg['require_deliv'] ), true, false ) . '> <b>Pour les achats PIN, exiger que le code ait été livré</b> <span class="hint">— les top-ups directs utilisent paid + completed comme preuve de réception.</span></label>';
	echo '<label style="display:block"><input type="checkbox" name="board_public" value="1"' . checked( ! empty( $cfg['board_public'] ), true, false ) . '> Classement visible sans être connecté <span class="hint">(les noms seront automatiquement masqués).</span></label>';
	echo '</td></tr>';

	echo '<tr><td><b>Mode test (simulation)</b></td><td>';
	echo '<label><input type="checkbox" name="dry_run" value="1"' . checked( ! empty( $cfg['dry_run'] ), true, false ) . '> '
		. '<b>🧪 Activer le mode test</b> — la progression s\'affiche normalement, mais <b>rien n\'est livré</b> : boutons grisés, aucune commande récompense, aucun prix hebdo.</label>';
	echo '<div class="hint">À utiliser quand vous changez les objectifs en cours de mois, ou pour vérifier une nouvelle configuration avant de l\'ouvrir aux clients.</div></td></tr>';

	if ( $board ) {
	echo '<tr><td><b>Calcul Meilleur Client</b></td><td><b>70 % montant net dépensé + 30 % nombre de commandes distinctes</b>'
		. '<div class="hint">Une commande payée et terminée compte une seule fois, même si elle contient plusieurs produits. Les montants remboursés sont retirés. En cas d’égalité : score, montant, commandes, puis résultat atteint le plus tôt.</div></td></tr>';

	$min_now  = delicat_board_min_order( delicat_competition_start() );
	$min_next = delicat_board_min_order( delicat_next_week_start() );
	echo '<tr><td><b>Montant minimum d\'une commande pour le classement</b></td><td><input type="number" min="0" max="100000" step="1" name="board_min_order" value="' . esc_attr( (string) (int) round( $min_next ) ) . '"> ' . esc_html( (string) delicat_cfg( 'currency' ) )
		. '<div class="hint">Une commande dont le montant éligible (après remboursement) est plus petit ne compte pas dans le classement, ni en montant ni en nombre de commandes : beaucoup de petites commandes ne battent plus un client qui dépense plus. 0 = toutes les commandes (règle d’origine). Un changement s’applique à partir de la semaine suivante ('
		. esc_html( wp_date( 'd/m/Y H:i', delicat_next_week_start() ) ) . '), jamais à la semaine en cours. Cette semaine : ' . ( $min_now > 0 ? esc_html( delicat_num( $min_now ) . ' ' . delicat_cfg( 'currency' ) ) : 'aucun minimum' ) . '. Annoncez la règle aux clients avant qu’elle commence.</div></td></tr>';

	echo '<tr><td><b>Prix Meilleur Client</b></td><td><input type="number" min="1" max="' . DELICAT_MAX_REWARD . '" name="prize" value="' . (int) $cfg['prize'] . '"> 💎';
	echo ' <span class="hint">' . esc_html( delicat_reward_value( $cfg['prize'] ) > 0 ? delicat_reward_money( delicat_reward_value( $cfg['prize'] ) ) : 'Montant HTG à configurer' ) . '</span></td></tr>';

	foreach ( array( 2 => 'prize2', 3 => 'prize3' ) as $rk => $pk ) {
		$pv = (int) $cfg[ $pk ];
		echo '<tr><td><b>Prix ' . $rk . 'e place</b></td><td><input type="number" min="0" max="' . DELICAT_MAX_REWARD . '" name="' . esc_attr( $pk ) . '" value="' . $pv . '"> 💎';
		if ( $pv > 0 ) {
			echo ' <span class="hint">' . esc_html( delicat_reward_value( $pv ) > 0 ? delicat_reward_money( delicat_reward_value( $pv ) ) : 'Montant HTG à configurer' ) . '</span>';
		} else {
			echo ' <span class="hint">0 = désactivé (seule la 1ère place gagne)</span>';
		}
		echo '</td></tr>';
	}

	}
	if ( ! $board ) {
	echo '<tr><td><b>Plafond de récompenses par client / mois</b></td><td><input type="number" min="0" max="' . DELICAT_MAX_MONTH_CAP . '" name="monthly_cap" value="' . (int) $cfg['monthly_cap'] . '"> 💎'
		. '<div class="hint">0 = illimité. Filet de sécurité : au-delà, le client ne peut plus réclamer ce mois-ci.</div></td></tr>';

	}
	if ( $board ) {
	$days = array( 'Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi' );
	echo '<tr><td><b>Reset du classement</b></td><td><select name="reset_day">';
	foreach ( $days as $i => $d ) echo '<option value="' . $i . '"' . selected( $i, (int) $cfg['reset_day'], false ) . '>' . esc_html( $d ) . '</option>';
	echo '</select> à <input type="number" min="0" max="23" name="reset_hour" value="' . (int) $cfg['reset_hour'] . '"> h</td></tr>';

	echo '<tr><td><b>Taille du classement</b></td><td><input type="number" min="1" max="50" name="board_size" value="' . (int) $cfg['board_size'] . '"></td></tr>';
	echo '<tr><td><b>Masquer les noms</b></td><td><label><input type="checkbox" name="mask_names" value="1"' . checked( ! empty( $cfg['mask_names'] ), true, false ) . '> Afficher « Jean R. » au lieu du nom complet</label></td></tr>';
	}
	echo '<tr><td><b>Lien WhatsApp</b></td><td><input type="url" class="regular-text" name="wa" value="' . esc_attr( $cfg['wa'] ) . '"></td></tr>';
	echo '</tbody></table></div>';

	if ( ! $board ) {
	/* texts */
	echo '<div class="box"><h3>Textes de la page client</h3><table class="widefat" style="border:0"><tbody>';
	$fields = array(
		'page_title'   => 'Titre de la page',
		'program_name' => 'Nom du programme',
		't_spend'      => 'Libellé « achats du mois »',
		't_reward'     => 'Libellé « récompense du mois »',
		't_progress'   => 'Titre de la section progression',
		'note'         => 'Note de bas de carte',
		'unit'         => 'Unité (DIAMANT)',
		'currency'     => 'Monnaie (G)',
		'badge_suffix' => 'Mot après le badge (Bonus)',
		'btn_prefix'   => 'Début du bouton (RECLAMER)',
		'btn_suffix'   => 'Fin du bouton (GRATUIT)',
		'btn_locked'   => 'Bouton quand la limite est atteinte',
		'btn_dry'      => 'Bouton en mode test',
		'notify_subject' => 'Objet de l\'email au client',
	);
	foreach ( $fields as $f => $label ) {
		$max = ( 'note' === $f ) ? 160 : DELICAT_TEXT_LEN;
		echo '<tr><td style="width:280px"><b>' . esc_html( $label ) . '</b></td>'
			. '<td><input type="text" class="regular-text" name="' . esc_attr( $f ) . '" value="' . esc_attr( (string) $cfg[ $f ] ) . '" maxlength="' . (int) $max . '"></td></tr>';
	}
	echo '</tbody></table></div>';

	}
	if ( $board ) {
	/* Meilleur Client texts */
	echo '<div class="box"><h3>Textes — page Meilleur Client</h3><p class="hint">Personnalisez les informations visibles dans le shortcode <code>[delicat_meilleur_client]</code> sur le site client.</p><table class="widefat" style="border:0"><tbody>';
	$board_fields = array(
		'board_kicker'       => 'Petit texte au-dessus du titre',
		'board_title'        => 'Titre principal',
		'board_games'        => 'Jeux/services affichés',
		'board_reset_label'  => 'Libellé du compte à rebours',
		'board_reward_label' => 'Libellé de la récompense',
		'board_prize_suffix' => 'Texte après le montant du prix',
		'board_reward_sub'   => 'Message sous la récompense',
		'board_you_label'    => 'Libellé position du client',
		'board_login_text'   => 'Texte lorsque le client est déconnecté',
		'board_heading'      => 'Titre du classement',
		'board_hidden_text'  => 'Texte de confidentialité',
		'board_empty_text'   => 'Message si le classement est vide',
		'board_first_text'   => 'Message pour la première place',
		'board_rank_text'    => 'Message de position — utilisez {rank}',
		'board_gap_text'     => 'Message de progression — {rank}, {gap}, {ahead}',
		'board_start_text'   => 'Message avant le premier achat',
		'board_footer_1'     => 'Pied de page — ligne 1',
		'board_footer_2'     => 'Pied de page — ligne 2',
	);
	foreach ( $board_fields as $f => $label ) {
		echo '<tr><td style="width:280px"><b>' . esc_html( $label ) . '</b></td><td><input type="text" class="large-text" name="' . esc_attr( $f ) . '" value="' . esc_attr( (string) $cfg[ $f ] ) . '" maxlength="160"></td></tr>';
	}
	echo '<tr><td><b>Lien du pied de page</b></td><td><input type="url" class="large-text" name="board_footer_url" value="' . esc_attr( (string) $cfg['board_footer_url'] ) . '"></td></tr>';
	echo '</tbody></table></div>';

	}
	/* notifications */
	echo '<div class="box"><h3>Email au client</h3>';
	echo '<p><label><input type="checkbox" name="notify_client" value="1"' . checked( ! empty( $cfg['notify_client'] ), true, false ) . '> '
		. '<b>Prévenir le client par email dès que sa récompense est livrée</b></label></p>';
	echo '<p class="hint">Balises disponibles : <code>{name}</code> <code>{tier}</code> <code>{reward}</code> <code>{unit}</code> <code>{order}</code> <code>{site}</code></p>';
	if ( ! $board ) echo '<p><b>Message — bonus réclamé</b><br><textarea name="notify_body" rows="6" class="large-text" maxlength="' . DELICAT_MSG_LEN . '">'
		. esc_textarea( (string) $cfg['notify_body'] ) . '</textarea></p>';
	if ( $board ) echo '<p><b>Message — gagnant Meilleur Client</b><br><textarea name="notify_winner" rows="5" class="large-text" maxlength="' . DELICAT_MSG_LEN . '">'
		. esc_textarea( (string) $cfg['notify_winner'] ) . '</textarea></p>';
	echo '<p class="hint">L\'email part avec la messagerie de WordPress. Si vos emails WooCommerce arrivent bien, celui-ci aussi.</p></div>';

	echo '<p><button class="button button-primary button-large">Enregistrer les réglages</button></p></form>';

	if ( $board ) return;
	/* reset */
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="box danger" onsubmit="return confirm(\'Réinitialiser toute la configuration Delicat Rewards ?\');">';
	wp_nonce_field( 'delicat_reset' );
	echo '<input type="hidden" name="action" value="delicat_reset">';
	echo '<h3>Réinitialiser la configuration</h3><p class="hint">Remet tiers, produits et textes aux valeurs d\'origine. Aucune commande, aucun code et aucune réclamation déjà faite n\'est touché.</p>';
	echo '<button class="button">Réinitialiser</button></form>';
}

/** One on/off row (posts to admin-post.php). */
function delicat_toggle_row( $key, $label, $on ) {
	$bg  = $on ? '#e7f6ec' : '#fde8e8';
	$bd  = $on ? '#bfe3cb' : '#f3c2c2';
	$st  = $on ? '🟢 ACTIVÉ' : '🔴 EN PAUSE';
	$des = $on ? 'Visible normalement par les clients.' : 'Les clients voient « Programme momentanément en pause ».';
	$btn = $on ? 'Mettre en pause' : 'Activer';

	$h  = '<div style="background:' . $bg . ';border:1px solid ' . $bd . ';border-radius:8px;padding:12px 14px;margin:8px 0;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">';
	$h .= '<div><b>' . esc_html( $label ) . ' : ' . $st . '</b><br><span class="hint">' . esc_html( $des ) . '</span></div>';
	$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
	$h .= wp_nonce_field( 'delicat_toggle', '_wpnonce', true, false );
	$h .= '<input type="hidden" name="action" value="delicat_toggle">';
	$h .= '<input type="hidden" name="which" value="' . esc_attr( $key ) . '">';
	$h .= '<button class="button">' . esc_html( $btn ) . '</button></form></div>';
	return $h;
}

/* =========================================================================
   ADMIN — RAPPORTS (monthly totals, CSV export, client simulator)
   ========================================================================= */

/** Last 12 month keys, newest first. 4.1.0: counted from the 1st, so a 29th–31st no longer skips February. */
function delicat_month_list() {
	$out   = array();
	$first = new DateTimeImmutable( 'first day of this month 00:00:00', wp_timezone() );
	for ( $i = 0; $i < 12; $i++ ) {
		$out[] = $first->modify( '-' . $i . ' month' )->format( 'Y-m' );
	}
	return $out;
}

/** Everything claimed in a given month, per client and per tier. Read-only. */
function delicat_month_claims( $month ) {
	$rows  = array();
	$users = get_users( array(
		'meta_key' => delicat_ledger_key( $month ),
		'number'   => 1000,
		'fields'   => array( 'ID' ),
	) );

	/* Ledgers created before 2.1 use the legacy per-tier keys. */
	if ( ! $users ) {
		foreach ( array_keys( array_merge( delicat_tier_history(), delicat_all_tiers() ) ) as $k ) {
			$legacy = get_users( array( 'meta_key' => "_delicat_claimed_{$month}_{$k}", 'number' => 1000, 'fields' => array( 'ID' ) ) );
			foreach ( $legacy as $u ) $users[] = $u;
		}
	}

	$seen  = array();
	$tiers = array_merge( delicat_tier_history(), delicat_all_tiers() );
	foreach ( $users as $u ) {
		$uid = (int) $u->ID;
		if ( isset( $seen[ $uid ] ) ) continue;
		$seen[ $uid ] = true;

		$ledger = delicat_ledger_get( $uid, $month );
		foreach ( $ledger['claims'] as $key => $n ) {
			if ( $n < 1 ) continue;
			$t = $tiers[ $key ] ?? array( 'label' => $key, 'reward' => 0 );
			$rows[] = array(
				'uid'      => $uid,
				'tier'     => $key,
				'label'    => (string) $t['label'],
				'claims'   => (int) $n,
				'diamonds' => (int) $n * (int) $t['reward'],
				'signed'   => ! empty( $ledger['ok'] ),
			);
		}
	}
	return $rows;
}

/** Reward orders issued during a month (the delivery record). */
function delicat_month_reward_orders( $month ) {
	if ( ! function_exists( 'wc_get_orders' ) || ! preg_match( '/^\d{4}-\d{2}$/', (string) $month ) ) return array();
	list( $start, $end ) = delicat_month_bounds( $month ); // 4.1.0: the site's month, not the server's (UTC)
	return delicat_orders_paged( array(
		'type'         => 'shop_order',
		'date_created' => $start . '...' . $end,
		'meta_key'     => '_delicat_reward',
		'meta_compare' => 'EXISTS',
	), 200, 5000 );
}

/* ---- CSV export ---- */
function delicat_csv_safe_row( array $row ) {
	foreach ( $row as &$v ) {
		if ( is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ) $v = "'" . $v;
	}
	unset( $v ); return $row;
}
add_action( 'admin_post_delicat_export', function () {
	delicat_guard( 'delicat_export' );

	$month = sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) );
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) $month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	$what = ( ( $_POST['what'] ?? '' ) === 'orders' ) ? 'orders' : 'claims';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=delicat-' . $what . '-' . $month . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads the accents

	if ( 'orders' === $what ) {
		fputcsv( $out, delicat_csv_safe_row( array( 'Commande', 'Date', 'Client ID', 'Identifiant', 'Email', 'Origine', 'Produits livrés', 'Total (G)' ) ) );
		foreach ( delicat_month_reward_orders( $month ) as $o ) {
			$u     = get_userdata( (int) $o->get_customer_id() );
			$items = array();
			foreach ( $o->get_items() as $it ) $items[] = $it->get_quantity() . '× ' . $it->get_name();
			fputcsv( $out, delicat_csv_safe_row( array(
				$o->get_id(),
				$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
				(int) $o->get_customer_id(),
				$u ? $u->user_login : '',
				$u ? $u->user_email : '',
				(string) $o->get_meta( '_delicat_reward' ),
				implode( ' | ', $items ),
				$o->get_total(),
			) ) );
		}
	} else {
		fputcsv( $out, delicat_csv_safe_row( array( 'Mois', 'Client ID', 'Identifiant', 'Nom', 'Email', 'Tier', 'Badge', 'Réclamations', 'Diamants offerts', 'Signature' ) ) );
		$total = 0;
		foreach ( delicat_month_claims( $month ) as $r ) {
			$u      = get_userdata( $r['uid'] );
			$total += $r['diamonds'];
			fputcsv( $out, delicat_csv_safe_row( array(
				$month,
				$r['uid'],
				$u ? $u->user_login : '',
				$u ? $u->display_name : '',
				$u ? $u->user_email : '',
				$r['tier'],
				$r['label'],
				$r['claims'],
				$r['diamonds'],
				$r['signed'] ? 'OK' : 'ALTEREE',
			) ) );
		}
		fputcsv( $out, delicat_csv_safe_row( array() ) );
		fputcsv( $out, delicat_csv_safe_row( array( 'TOTAL', '', '', '', '', '', '', '', $total, '' ) ) );
	}
	fclose( $out );
	delicat_audit_add( sprintf( 'Export CSV (%s) du mois %s.', $what, $month ) );
	exit;
} );

function delicat_tab_reports() {
	$months = delicat_month_list();
	$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : $months[0];
	if ( ! in_array( $month, $months, true ) ) $month = $months[0];

	/* month picker */
	echo '<form method="get" style="margin-bottom:16px"><input type="hidden" name="page" value="delicat-rewards">'
		. '<input type="hidden" name="tab" value="rapports"><label><b>Mois :</b> <select name="month" onchange="this.form.submit()">';
	foreach ( $months as $m ) echo '<option value="' . esc_attr( $m ) . '"' . selected( $m, $month, false ) . '>' . esc_html( $m ) . '</option>';
	echo '</select></label></form>';

	/* totals per tier */
	$rows  = delicat_month_claims( $month );
	$per   = array();
	$total = 0; $clients = array();
	foreach ( $rows as $r ) {
		if ( ! isset( $per[ $r['tier'] ] ) ) $per[ $r['tier'] ] = array( 'label' => $r['label'], 'claims' => 0, 'diamonds' => 0 );
		$per[ $r['tier'] ]['claims']   += $r['claims'];
		$per[ $r['tier'] ]['diamonds'] += $r['diamonds'];
		$total += $r['diamonds'];
		$clients[ $r['uid'] ] = true;
	}

	echo '<div class="box"><h3>Ce que le programme vous a coûté en ' . esc_html( $month ) . '</h3>';
	if ( ! $per ) {
		echo '<p><em>Aucune réclamation ce mois-ci.</em></p>';
	} else {
		echo '<table class="form"><thead><tr><th>Tier</th><th>Réclamations</th><th>Diamants offerts</th></tr></thead><tbody>';
		foreach ( $per as $key => $p ) {
			echo '<tr><td><b>' . esc_html( $p['label'] ) . '</b> <code>' . esc_html( $key ) . '</code></td>'
				. '<td>' . (int) $p['claims'] . '</td><td><b>' . (int) $p['diamonds'] . ' 💎</b></td></tr>';
		}
		echo '<tr><td><b>TOTAL</b> — ' . count( $clients ) . ' client(s)</td><td></td><td><b>' . (int) $total . ' 💎</b></td></tr>';
		echo '</tbody></table>';
	}
	echo '</div>';

	/* exports */
	echo '<div class="box"><h3>Export comptable</h3><p class="hint">Fichier CSV, ouvrable dans Excel ou Google Sheets.</p>';
	foreach ( array( 'claims' => 'Exporter les réclamations (CSV)', 'orders' => 'Exporter les commandes récompense (CSV)' ) as $what => $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:10px">';
		echo wp_nonce_field( 'delicat_export', '_wpnonce', true, false );
		echo '<input type="hidden" name="action" value="delicat_export">';
		echo '<input type="hidden" name="what" value="' . esc_attr( $what ) . '">';
		echo '<input type="hidden" name="month" value="' . esc_attr( $month ) . '">';
		echo '<button class="button button-primary">' . esc_html( $label ) . '</button></form>';
	}
	echo '</div>';

	/* client simulator */
	$q = isset( $_GET['sim'] ) ? sanitize_text_field( wp_unslash( $_GET['sim'] ) ) : '';
	echo '<div class="box"><h3>Simulateur — que verrait ce client ?</h3>'
		. '<p class="hint">Lecture seule. Recalcule la progression réelle du client avec la configuration actuelle : parfait pour vérifier l\'effet d\'un changement d\'objectif avant de l\'ouvrir à tout le monde.</p>';
	echo '<form method="get" style="margin-bottom:12px"><input type="hidden" name="page" value="delicat-rewards">'
		. '<input type="hidden" name="tab" value="rapports"><input type="hidden" name="month" value="' . esc_attr( $month ) . '">'
		. '<input type="search" name="sim" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="Identifiant, email ou ID du client"> '
		. '<button class="button">Simuler</button></form>';

	if ( '' !== $q ) {
		$user = is_numeric( $q ) ? get_userdata( (int) $q ) : ( get_user_by( 'login', $q ) ?: get_user_by( 'email', $q ) );
		if ( ! $user ) {
			echo '<p><em>Client introuvable.</em></p>';
		} else {
			$state = delicat_compute_state( $user->ID ); // read-only: computes, never writes
			$tiers = delicat_tiers();
			echo '<p><b>' . esc_html( $user->display_name ) . '</b> <code>#' . (int) $user->ID . '</code> · achats du mois : <b>'
				. esc_html( (string) $state['monthSpend'] ) . ' ' . esc_html( (string) delicat_cfg( 'currency' ) ) . '</b> · déjà offert : <b>'
				. (int) $state['monthReward'] . ' 💎</b></p>';
			echo '<table class="form"><thead><tr><th>Tier</th><th>Progression</th><th>Déjà réclamé</th><th>Peut réclamer ?</th></tr></thead><tbody>';
			foreach ( $tiers as $key => $t ) {
				$s   = $state['tiers'][ $key ] ?? array();
				$why = $s['blocked'] ?? '';
				$lbl = ! empty( $s['claimable'] ) ? '<b style="color:#1a7f37">✅ OUI — ' . (int) $t['reward'] . ' 💎</b>'
					: ( 'cap'    === $why ? '⛔ plafond mensuel atteint'
					: ( 'max'    === $why ? '⛔ limite de réclamations atteinte'
					: ( 'dry'    === $why ? '🧪 éligible, mais mode test actif'
					: ( 'frozen' === $why ? '⛔ compte gelé (intégrité)'
					: '⏳ pas encore' ) ) ) );
				echo '<tr><td><b>' . esc_html( $t['label'] ) . '</b></td>'
					. '<td>' . (int) ( $s['progress'] ?? 0 ) . ' / ' . (int) $t['goal'] . ' fois</td>'
					. '<td>' . (int) ( $s['claimed'] ?? 0 ) . '×</td>'
					. '<td>' . $lbl . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
	echo '</div>';
}

/* ---- TAB: DIAGNOSTICS ---- */
function delicat_stock_cell( $pid ) {
	$pid = (int) $pid;
	if ( ! $pid ) return '—';
	if ( ! function_exists( 'delicat_keys_available' ) ) return '<span class="hint" title="Activez Delicat Keys">n/a</span>';
	$n     = (int) delicat_keys_available( $pid );
	$color = 0 === $n ? '#b32d2e' : ( $n <= 5 ? '#b26a00' : '#1a7f37' );
	$warn  = 0 === $n ? ' ⛔' : ( $n <= 5 ? ' ⚠️' : '' );
	return '<b style="color:' . $color . '">' . $n . $warn . '</b>';
}

function delicat_tab_diagnostics() {
	/* Integrity panel */
	$tamper = get_option( 'delicat_tamper', array() );
	if ( is_array( $tamper ) && $tamper ) {
		echo '<div class="notice notice-error" style="margin:0 0 16px"><p><b>⛔ Alerte d\'intégrité</b><br>'
			. 'Les compteurs de réclamations de ces comptes ne correspondent plus à leur signature : ils ont été modifiés en dehors du plugin. '
			. 'Leurs réclamations sont gelées.</p><ul style="margin-left:18px;list-style:disc">';
		foreach ( array_slice( $tamper, 0, 10 ) as $row ) {
			$u = get_userdata( (int) ( $row['u'] ?? 0 ) );
			echo '<li>' . esc_html( $row['t'] ?? '' ) . ' — <b>' . esc_html( $u ? $u->user_login : ( '#' . (int) ( $row['u'] ?? 0 ) ) ) . '</b> : '
				. esc_html( $row['w'] ?? '' ) . '</li>';
		}
		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'delicat_clear_tamper' );
		echo '<input type="hidden" name="action" value="delicat_clear_tamper">';
		echo '<button class="button">J\'ai vérifié — effacer les alertes</button></form></div>';
	} else {
		echo '<div class="notice notice-success" style="margin:0 0 16px"><p>🔐 Intégrité : aucune altération détectée. '
			. 'Les compteurs de bonus sont signés (HMAC-SHA256) et les boutons « Réclamer » portent un jeton signé de 30 minutes.</p></div>';
	}

	/* 3.4.2: the board is rebuilt in the background (WP-Cron / Action Scheduler). */
	$built = delicat_leaderboard_built();
	echo '<div class="box"><h3>Classement Meilleur client</h3><p>' . ( $built ? 'Calculé en arrière-plan, dernière fois le <b>' . esc_html( wp_date( 'd/m/Y H:i:s', $built ) ) . '</b>.' : 'Pas encore calculé : il le sera à la prochaine tâche planifiée.' )
		. ' <span class="hint">La page des clients lit toujours ce classement enregistré ; il est recalculé environ 30 secondes après chaque commande modifiée, par les tâches planifiées de WordPress' . ( delicat_board_queue() ? ' (WooCommerce › État › Actions planifiées, groupe « delicat-rewards »)' : '' ) . '. S’il a plus d’une heure alors que des commandes arrivent, vérifiez que WP-Cron fonctionne (ou ajoutez une tâche cron serveur sur wp-cron.php).</span></p></div>';

	/* Signing key: tell a salt change from fraud and offer the recovery. */
	$reseal_month = wp_date( 'Y-m', delicat_month_start(), wp_timezone() );
	echo '<div class="box"><h3>Clé de signature des bonus</h3>';
	if ( delicat_key_changed() ) {
		echo '<p style="color:#b32d2e"><b>⛔ La clé de signature a changé</b> (salts WordPress modifiés, déménagement du site…). Les registres signés avant ce changement sont gelés : ce n’est pas une fraude. Re-scellez-les ci-dessous.</p>';
	}
	echo '<p class="hint">' . ( defined( 'DELICAT_REWARDS_HMAC_KEY' )
		? '✅ Clé dédiée <code>DELICAT_REWARDS_HMAC_KEY</code> active : un changement des salts WordPress ne gèle plus les bonus.'
		: '⚠️ Les registres sont signés avec les salts WordPress. Avant tout changement de salts, ajoutez dans <code>wp-config.php</code> une clé dédiée d’au moins 32 caractères : <code>define( \'DELICAT_REWARDS_HMAC_KEY\', \'…\' );</code>. Les registres existants restent valides et sont re-scellés à la lecture.' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'delicat_reseal' );
	echo '<input type="hidden" name="action" value="delicat_reseal"><p><label>Mois : <select name="month">';
	foreach ( array( $reseal_month, delicat_month_shift( $reseal_month, -1 ) ) as $m ) echo '<option value="' . esc_attr( $m ) . '">' . esc_html( $m ) . '</option>';
	echo '</select></label> <button class="button">Re-sceller les registres du mois</button></p>';
	echo '<p class="hint">Répare uniquement les registres gelés. Aucune réclamation n’est baissée : chaque bonus garde le plus haut des compteurs enregistrés et des commandes récompense du mois (sauf annulées ou échouées). Effacez ensuite les alertes.</p></form></div>';

	echo '<div class="box"><h3>Ce qui compte comme un achat réel</h3><p class="hint">'
		. '• Commande d\'un <b>compte connecté</b> (les commandes invité ne comptent jamais)<br>'
		. '• Statut : <code>' . esc_html( implode( ', ', delicat_counting_statuses() ) ) . '</code><br>'
		. '• Paiement confirmé : <b>' . ( delicat_cfg( 'require_paid' ) ? 'exigé ✅' : 'NON exigé ⚠️' ) . '</b><br>'
		. '• Remboursements déduits : <b>' . ( delicat_cfg( 'ignore_refund' ) ? 'oui ✅' : 'non ⚠️' ) . '</b><br>'
		. '• Livraison du code exigée pour les achats PIN : <b>' . ( delicat_cfg( 'require_deliv' ) ? 'oui' : 'non' ) . '</b><br>'
		. '• Les commandes récompense (0 G) ne comptent jamais.</p>';

	/* Reality check: would the "paid" rule silently exclude your real orders? */
	if ( function_exists( 'wc_get_orders' ) ) {
		$recent  = wc_get_orders( array( 'type' => 'shop_order', 'status' => delicat_counting_statuses(), 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) ); // 3.4.2: without 'type' a refund came back too and stopped this tab
		$unpaid  = 0; $guest = 0; $total = 0;
		foreach ( $recent as $o ) {
			if ( $o->get_meta( '_delicat_reward' ) ) continue;
			$total++;
			if ( ! $o->get_date_paid() )        $unpaid++;
			if ( ! (int) $o->get_customer_id() ) $guest++;
		}
		if ( $total ) {
			$style = $unpaid ? 'color:#b26a00;font-weight:600' : 'color:#1a7f37;font-weight:600';
			echo '<p style="' . $style . '">Sur les ' . (int) $total . ' dernières commandes : <b>' . (int) $unpaid . '</b> sans date de paiement'
				. ( $unpaid ? ' ⚠️ (elles ne compteront pas tant que « paiement confirmé » est exigé — vérifiez votre passerelle)' : ' ✅' )
				. ' · <b>' . (int) $guest . '</b> passées sans compte' . ( $guest ? ' (jamais comptées)' : '' ) . '.</p>';
		}
	}
	echo '</div>';

	$problems = delicat_config_problems();
	if ( $problems ) {
		echo '<div class="notice notice-warning" style="margin:0 0 16px"><p><b>' . count( $problems ) . ' problème(s) :</b><br>'
			. implode( '<br>', array_map( 'esc_html', $problems ) ) . '</p></div>';
	} else {
		echo '<div class="notice notice-success" style="margin:0 0 16px"><p>✅ Configuration cohérente : achats Free Fire mappés et montants HTG des récompenses configurés.</p></div>';
	}

	echo '<table class="form"><thead><tr><th>Tier</th><th>Statut</th><th>Achats éligibles (PIN/direct/serveurs)</th><th>Objectif</th><th>Récompense livrée</th></tr></thead><tbody>';
	foreach ( delicat_all_tiers() as $key => $raw ) {
		$on   = ! empty( $raw['enabled'] );
		$ids  = array();
		foreach ( (array) ( $raw['ids'] ?? array() ) as $id ) { if ( delicat_valid_pid( $id ) ) $ids[] = (int) $id; }
		$fb = delicat_pid_by_diamonds( (int) ( $raw['pin'] ?? 0 ) ); if ( $fb ) $ids[] = $fb;
		$ids = array_values( array_unique( $ids ) );

		$plan = delicat_reward_plan( (int) ( $raw['reward'] ?? 0 ) );
		$rew  = '<b>' . (int) ( $raw['reward'] ?? 0 ) . ' 💎</b> = ';
		if ( $plan ) {
			$parts = array();
			foreach ( $plan as $l ) {
				$pid     = delicat_pid_by_diamonds( $l['denom'] );
				$deliv   = delicat_purchasable( $pid );
				$did     = $deliv ? $deliv->get_id() : 0;
				$extra   = ( $did && $did !== (int) $pid ) ? ' <span class="hint">→ livré : ' . esc_html( $deliv->get_name() ) . ' <code>#' . (int) $did . '</code></span>' : '';
				if ( ! $deliv ) $extra = ' <b style="color:#b32d2e">⛔ aucune variation achetable</b>';
				$parts[] = $l['qty'] . '× PIN ' . (int) $l['denom'] . ' ' . delicat_pid_hint( $pid ) . $extra . ' · stock ' . delicat_stock_cell( $did ?: $pid );
			}
			$rew .= implode( '<br>', $parts );
		} else {
			$rew .= '<b style="color:#b32d2e">⛔ non composable</b>';
		}

		echo '<tr><td><b>' . esc_html( $raw['label'] ?? $key ) . '</b><div class="hint"><code>' . esc_html( $key ) . '</code></div></td>';
		echo '<td>' . ( $on ? '🟢 actif' : '⚪️ inactif' ) . '</td>';
		echo '<td>' . ( $ids ? implode( '<br>', array_map( 'delicat_pid_hint', $ids ) ) : '<b style="color:#b32d2e">⛔ aucun</b>' ) . '</td>';
		echo '<td>' . (int) ( $raw['goal'] ?? 0 ) . ' fois</td>';
		echo '<td>' . $rew . '</td></tr>';
	}
	echo '</tbody></table>';

	/* Count the explicit configured map without scanning the whole product catalog. */
	$count = count( delicat_ff_pin_ids() );
	echo '<p style="margin-top:16px">Produits explicitement configurés pour Free Fire : <b>' . (int) $count . '</b> · dénominations : <code>'
		. esc_html( implode( ', ', delicat_ff_denoms() ) ) . '</code></p>';

	if ( function_exists( 'delicat_is_keyed' ) ) {
		$not_keyed = array();
		foreach ( delicat_ff_denoms() as $d ) {
			$pid = delicat_pid_by_diamonds( $d );
			if ( $pid && ! delicat_is_keyed( $pid ) ) $not_keyed[] = $d . ' 💎 (#' . $pid . ')';
		}
		if ( $not_keyed ) {
			echo '<p style="color:#b26a00;font-weight:600">⚠️ Produits trouvés mais NON activés pour la livraison — cochez « Delicat Keys » sur : '
				. esc_html( implode( ', ', $not_keyed ) ) . ', sinon l\'achat ne livrera aucun code.</p>';
		} else {
			echo '<p style="color:#1a7f37;font-weight:600">✅ Tous les PINs sont activés pour la livraison (Delicat Keys).</p>';
		}
	} else {
		echo '<p class="hint">Activez « Delicat Store — Secure Keys » pour voir le stock de codes et la livraison.</p>';
	}
	echo '<p class="hint">Cette page est en lecture seule : aucune commande, aucun solde et aucun code n\'est modifié.</p>';
}

/* ---- TAB: JOURNAL ---- */
function delicat_tab_audit() {
	$log = get_option( 'delicat_audit', array() );
	if ( ! is_array( $log ) || ! $log ) {
		echo '<p><em>Aucune modification enregistrée pour l\'instant.</em></p>';
		return;
	}
	echo '<p class="hint">Les 40 dernières modifications de configuration : qui, quand, depuis quelle IP. Les réclamations des clients, elles, sont tracées dans les commandes récompense (note de commande).</p>';
	echo '<table class="form"><thead><tr><th style="width:170px">Date</th><th style="width:150px">Utilisateur</th><th style="width:140px">IP</th><th>Modification</th></tr></thead><tbody>';
	foreach ( $log as $row ) {
		echo '<tr><td>' . esc_html( $row['t'] ?? '' ) . '</td><td>' . esc_html( $row['u'] ?? '' ) . '</td>'
			. '<td><code>' . esc_html( $row['ip'] ?? '' ) . '</code></td><td>' . esc_html( $row['w'] ?? '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

/* =========================================================================
   ADMIN — PARTICIPANTS
   ========================================================================= */
function delicat_week_participants() {
	/* Include excluded accounts here so an administrator can still inspect and
	 * reinstate them. The public leaderboard continues to exclude them. */
	return delicat_collect_leaderboard_rows( delicat_competition_start(), null, true );
}

function delicat_participants_page() {
	if ( ! current_user_can( delicat_cap() ) ) {
		wp_die( 'Accès refusé.', 'Delicat Rewards', array( 'response' => 403 ) );
	}

	if ( isset( $_POST['delicat_part_uid'] ) && check_admin_referer( 'delicat_participants' ) ) {
		$uid = (int) $_POST['delicat_part_uid'];
		$off = ( ( $_POST['delicat_part_to'] ?? '' ) === 'off' );
		if ( $uid && get_userdata( $uid ) ) {
			delicat_set_excluded( $uid, $off );
			delicat_audit_add( sprintf( 'Participant %s : %s', delicat_display_name( $uid ), $off ? 'retiré' : 'réintégré' ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( delicat_display_name( $uid ) ) . ' : '
				. ( $off ? '🔴 retiré du classement' : '🟢 affiché dans le classement' ) . '.</p></div>';
		}
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

	echo '<div class="wrap"><h1>Delicat — Participants</h1>';
	echo '<p style="max-width:760px;color:#555;"><b>🟢 Affiché</b> : le client participe normalement. <b>🔴 Masqué</b> : son nom est retiré du classement et il ne peut plus réclamer de récompense. Par défaut, tout le monde participe.</p>';

	echo '<form method="get" style="margin:10px 0;"><input type="hidden" name="page" value="delicat-participants">';
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Rechercher un client (nom, email)" style="width:280px;padding:6px;"> ';
	echo '<button class="button">Rechercher</button></form>';

	$weekly = delicat_week_participants();
	$show   = array();
	if ( '' !== $search ) {
		$users = get_users( array( 'search' => '*' . $search . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'number' => 50, 'fields' => array( 'ID' ) ) );
		foreach ( $users as $u ) $show[ (int) $u->ID ] = $weekly[ (int) $u->ID ] ?? array();
	} else {
		$show = $weekly;
		$ex = get_users( array( 'meta_key' => '_delicat_excluded', 'meta_value' => '1', 'number' => 200, 'fields' => array( 'ID' ) ) );
		foreach ( $ex as $u ) if ( ! isset( $show[ (int) $u->ID ] ) ) $show[ (int) $u->ID ] = array();
	}

	if ( ! $show ) {
		echo '<p><em>' . ( '' !== $search ? 'Aucun client trouvé.' : 'Aucun participant cette semaine. Utilisez la recherche pour trouver un client.' ) . '</em></p></div>';
		return;
	}

	echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Client</th><th>Email</th><th>Commandes terminées</th><th>Montant net dépensé</th><th style="width:230px">Participation</th></tr></thead><tbody>';
	foreach ( $show as $uid => $ctx ) {
		$u = get_userdata( $uid );
		if ( ! $u ) continue;
		$excluded = delicat_is_excluded( $uid );
		$orders   = max( 0, (int) ( $ctx['orders'] ?? 0 ) );
		$spend    = max( 0.0, (float) ( $ctx['spend'] ?? 0 ) );
		echo '<tr><td><b>' . esc_html( $u->display_name ?: $u->user_login ) . '</b></td>';
		echo '<td>' . esc_html( $u->user_email ) . '</td>';
		echo '<td>' . ( $orders ? (int) $orders : '—' ) . '</td>';
		echo '<td>' . ( $spend > 0 && function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $spend ) ) : '—' ) . '</td><td>';
		echo '<form method="post" style="margin:0;display:flex;gap:8px;align-items:center;">';
		wp_nonce_field( 'delicat_participants' );
		echo '<input type="hidden" name="delicat_part_uid" value="' . (int) $uid . '">';
		if ( $excluded ) {
			echo '<input type="hidden" name="delicat_part_to" value="on">';
			echo '<span style="color:#b32d2e;font-weight:700;">🔴 Masqué</span>';
			echo '<button class="button button-primary">Afficher</button>';
		} else {
			echo '<input type="hidden" name="delicat_part_to" value="off">';
			echo '<span style="color:#1a7f37;font-weight:700;">🟢 Affiché</span>';
			echo '<button class="button">Retirer</button>';
		}
		echo '</form></td></tr>';
	}
	echo '</tbody></table></div>';
}


/* ---- Licence server: dashboard (the public route lives in delicat-rewards.php) ---- */
add_action( 'admin_menu', function(){ if ( delicat_license_server_on() ) add_submenu_page('woocommerce','Rewards Licences','Rewards Licences',delicat_cap(),'delicat-rewards-licenses','delicat_license_admin_page'); }, 40 );
add_action( 'admin_post_delicat_license_create', function(){
	delicat_guard('delicat_license_create');
	if ( ! delicat_license_server_on() ) wp_die( 'Serveur de licences désactivé.', 'Delicat Rewards', array( 'response' => 403 ) );
	if ( ! delicat_license_lock( 5 ) ) wp_die( 'Le registre des licences est occupé. Réessayez.', 'Delicat Rewards', array( 'response' => 503 ) );
	try {
		$raw=strtoupper(wp_generate_password(32,false,false)); $key='DLR-'.substr($raw,0,8).'-'.substr($raw,8,8).'-'.substr($raw,16,8).'-'.substr($raw,24,8); $h=hash('sha256',$key);
		$expires=sanitize_text_field(wp_unslash($_POST['expires']??'')); if($expires && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$expires)) $expires='';
		$note=sanitize_text_field(wp_unslash($_POST['note']??'')); $all=delicat_license_records(); $all[$h]=array('last4'=>substr($key,-4),'active'=>1,'domain'=>'','expires'=>$expires,'note'=>$note,'created'=>current_time('mysql')); delicat_license_save_records($all);
	} finally { delicat_license_unlock(); }
	set_transient('delicat_new_license_'.get_current_user_id(),$key,120); wp_safe_redirect(admin_url('admin.php?page=delicat-rewards-licenses')); exit;
});
add_action( 'admin_post_delicat_license_action', function(){
	delicat_guard('delicat_license_action');
	$h=preg_replace('/[^a-f0-9]/','',strtolower((string)($_POST['hash']??''))); $do=sanitize_key($_POST['do']??'');
	if ( ! delicat_license_lock( 5 ) ) wp_die( 'Le registre des licences est occupé. Réessayez.', 'Delicat Rewards', array( 'response' => 503 ) );
	try {
		$all=delicat_license_records(); if(isset($all[$h])){ if($do==='toggle')$all[$h]['active']=empty($all[$h]['active'])?1:0; elseif($do==='unbind'){ $all[$h]['domain']=''; unset($all[$h]['activated_at']); } elseif($do==='delete')unset($all[$h]); delicat_license_save_records($all);}
	} finally { delicat_license_unlock(); }
	wp_safe_redirect(admin_url('admin.php?page=delicat-rewards-licenses')); exit;
});
function delicat_license_admin_page(){
	if(!current_user_can(delicat_cap()))wp_die('Accès refusé.'); $all=delicat_license_records(); $new=get_transient('delicat_new_license_'.get_current_user_id()); if($new)delete_transient('delicat_new_license_'.get_current_user_id());
	echo '<div class="wrap"><h1>Delicat Rewards — Licences</h1><p>URL du serveur à saisir dans le Client : <code>'.esc_html(home_url()).'</code></p>';
	if($new)echo '<div class="notice notice-success"><p><b>Nouvelle clé — copiez-la maintenant :</b><br><code style="font-size:16px">'.esc_html($new).'</code></p></div>';
	echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="background:#fff;padding:16px;max-width:800px">'; wp_nonce_field('delicat_license_create'); echo '<input type="hidden" name="action" value="delicat_license_create"><label>Expiration <input type="date" name="expires"></label> &nbsp; <label>Note <input type="text" name="note" maxlength="120"></label> <button class="button button-primary">Générer une licence</button></form><br>';
	echo '<table class="widefat striped"><thead><tr><th>Clé</th><th>État</th><th>Domaine</th><th>Expiration</th><th>Dernière vérification</th><th>Note</th><th>Actions</th></tr></thead><tbody>';
	foreach($all as $h=>$l){echo '<tr><td>•••• '.esc_html($l['last4']??'').'</td><td>'.(!empty($l['active'])?'Active':'Révoquée').'</td><td>'.esc_html($l['domain']??'—').'</td><td>'.esc_html($l['expires']??'—').'</td><td>'.esc_html($l['last_check']??'—').'</td><td>'.esc_html($l['note']??'').'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';wp_nonce_field('delicat_license_action');echo '<input type="hidden" name="action" value="delicat_license_action"><input type="hidden" name="hash" value="'.esc_attr($h).'"><button class="button" name="do" value="toggle">'.(!empty($l['active'])?'Révoquer':'Réactiver').'</button> <button class="button" name="do" value="unbind">Délier</button> <button class="button" name="do" value="delete" onclick="return confirm(\'Supprimer cette licence ?\')">Supprimer</button></form></td></tr>';}
	if(!$all)echo '<tr><td colspan="7">Aucune licence.</td></tr>'; echo '</tbody></table></div>';
}
