<?php
/**
 * Delicat Assistant 7.1 — answers that follow what the customer actually asked.
 *
 * - « #23170 », « commande 23170 », « kòmand 23170 », « order 23170 » (or the number alone): the real
 *   status of the SIGNED-IN customer's OWN order. A number that belongs to someone else gets exactly the
 *   same answer as a number that does not exist, lookups are limited per account and per address, and a
 *   visitor is asked to sign in. The card offers « Partager le reçu »: a summary (number, status, date,
 *   items, total, payment method) without address, phone, e-mail, PIN or code, Player ID or notes.
 * - « Où est ma commande ? » from a signed-in customer: their last order, the same card.
 * - PIN / top-up not received, invalid code, wrong Player ID: the specific answer, before the generic
 *   « problème de paiement » guide that used to catch them.
 * - The rest of the store, from live settings: Programme Revendeur, Parrainage, Envoyer de l'argent,
 *   account security. Nothing private beyond the asking customer's own figures.
 * - Short memory: after « voulez-vous un agent ? », a « oui » connects.
 *
 * Called from DSA2_Data::smart_reply() and DSA2_Data::smart_reply_core().
 */
defined( 'ABSPATH' ) || exit;

final class DSA2_Brain {

	const LOOKUPS_PER_HOUR = 30;   // per account (and three times that per address)
	const PENDING_TTL      = 600;  // how long « oui » still answers the bot's last question

	private static function t( $lang, $fr, $en, $ht ) {
		return 'en' === $lang ? $en : ( 'ht' === $lang ? $ht : $fr );
	}
	/** Lower case, no accents, punctuation (’ ' - ? !…) as spaces; « # » kept for order numbers. */
	private static function norm( $text ) {
		$t = remove_accents( strtolower( wp_strip_all_tags( (string) $text ) ) );
		return trim( preg_replace( '/[^\p{L}\p{N}#]+/u', ' ', $t ) );
	}

	/* ============================================================ the bot's last question */

	public static function remember( $conv, $what ) {
		if ( $conv && ! empty( $conv->id ) ) set_transient( 'dap_pend_' . (int) $conv->id, (string) $what, self::PENDING_TTL );
	}
	/** « oui / wi / yes / ok » right after the bot offered something: what was offered, once. */
	public static function answer_to_pending( $conv, $body ) {
		if ( ! $conv || empty( $conv->id ) ) return '';
		$t = self::norm( $body );
		if ( ! preg_match( '/^(oui|ouais|wi|wi wi|yes|yeah|yep|ok|okay|oke|dako|d accord|daccord|bien sur|of course|sure|svp|s il vous plait|please|tanpri|wi tanpri|yes please|oui svp)[ !.]*$/', $t ) ) return '';
		$key  = 'dap_pend_' . (int) $conv->id;
		$what = (string) get_transient( $key );
		if ( '' === $what ) return '';
		delete_transient( $key );
		return $what;
	}

	/* ============================================================ orders */

	/** An order number written by the customer, or ''. */
	public static function order_number( $text ) {
		$n = self::norm( $text );
		$not_amount = '(?!\s*(?:diam|dyam|dia\b|uc\b|cp\b|robux|htg|gde|goud|pin\b|jeton|piece|pyes|\$|usd|g\b))';
		if ( preg_match( '/#\s*(\d{2,10})\b' . $not_amount . '/', $n, $m ) ) return $m[1];
		if ( preg_match( '/\b(?:commande|cmd|order|komand|kmand|achat|facture|recu|receipt|resi)\s*(?:n\s*o\b|no\b|n°|num(?:ero)?|nimewo|number|numero)?\s*[.:#]?\s*(\d{3,10})\b' . $not_amount . '/', $n, $m ) ) return $m[1];
		if ( preg_match( '/^(\d{4,10})$/', $n, $m ) ) return $m[1]; // the number alone
		return '';
	}

	/** The customer's own order with that number, or null (also for someone else's order). */
	public static function own_order( $uid, $number ) {
		$uid = (int) $uid;
		if ( $uid < 1 || '' === (string) $number || ! function_exists( 'wc_get_order' ) ) return null;
		$order = wc_get_order( absint( $number ) );
		if ( $order instanceof WC_Order && (int) $order->get_customer_id() === $uid
			&& (string) $order->get_order_number() === (string) $number && 'checkout-draft' !== $order->get_status() ) {
			return $order;
		}
		// Order numbers that differ from the internal ids (sequential-number plugins): this customer's orders only.
		foreach ( (array) wc_get_orders( array( 'customer_id' => $uid, 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) ) as $o ) {
			if ( $o instanceof WC_Order && (string) $o->get_order_number() === (string) $number && 'checkout-draft' !== $o->get_status() ) return $o;
		}
		return null;
	}

	/** Fixed one-hour window per account, and per address for every caller. */
	private static function lookup_allowed( $uid ) {
		$ip   = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$keys = array( 'dap_olk_ip_' . md5( $ip ) => self::LOOKUPS_PER_HOUR * 3 );
		if ( $uid > 0 ) $keys[ 'dap_olk_u_' . (int) $uid ] = self::LOOKUPS_PER_HOUR;
		foreach ( $keys as $k => $limit ) {
			if ( (int) get_transient( $k ) >= $limit ) return false;
		}
		foreach ( $keys as $k => $limit ) set_transient( $k, (int) get_transient( $k ) + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** The reply to an order number. */
	public static function order_reply( $number, $lang, $uid ) {
		$uid = (int) $uid;
		if ( $uid < 1 ) {
			return array( 'type' => 'help_card', 'body' => 'order_sign_in', 'meta' => array(
				'title'   => self::t( $lang, 'Connectez-vous pour suivre la commande', 'Sign in to track the order', 'Konekte pou swiv kòmand lan' ),
				'intro'   => self::t( $lang,
					'Pour protéger vos achats, je montre une commande uniquement à son propriétaire connecté. Connectez-vous, puis retapez #' . $number . '.',
					'To protect your purchases, I only show an order to its signed-in owner. Sign in, then type #' . $number . ' again.',
					'Pou pwoteje acha ou yo, mwen montre yon kòmand sèlman bay moun ki fè l la lè l konekte. Konekte, epi tape #' . $number . ' ankò.' ),
				'buttons' => array(
					array( 'label' => self::t( $lang, 'Se connecter', 'Sign in', 'Konekte' ), 'action' => 'url', 'value' => self::account_url() ),
					array( 'label' => self::t( $lang, 'Sans compte : numéro + e-mail', 'No account: number + e-mail', 'San kont: nimewo + imel' ), 'action' => 'quick', 'value' => 'order_center' ),
				),
			) );
		}
		if ( ! self::lookup_allowed( $uid ) ) {
			return array( 'type' => 'help_card', 'body' => 'order_limit', 'meta' => array(
				'title' => self::t( $lang, 'Trop de recherches', 'Too many searches', 'Twòp rechèch' ),
				'intro' => self::t( $lang, 'Vous avez cherché beaucoup de commandes en peu de temps. Réessayez dans une heure, ou ouvrez Mes commandes.', 'You searched many orders in a short time. Try again in an hour, or open My orders.', 'Ou chèche anpil kòmand nan yon ti tan. Eseye ankò nan inèdtan, oswa louvri Kòmand mwen yo.' ),
				'buttons' => array( array( 'label' => self::t( $lang, 'Mes commandes', 'My orders', 'Kòmand mwen yo' ), 'action' => 'url', 'value' => self::orders_url() ) ),
			) );
		}
		$order = self::own_order( $uid, $number );
		if ( ! $order ) {
			return array( 'type' => 'help_card', 'body' => 'order_not_found:' . $number, 'meta' => array(
				'title'   => sprintf( self::t( $lang, 'Commande #%s introuvable', 'Order #%s not found', 'Nou pa jwenn kòmand #%s' ), $number ),
				'intro'   => sprintf( self::t( $lang,
					'Je ne trouve pas la commande #%s dans votre compte. Vérifiez le numéro (il figure dans l’e-mail de confirmation) ou ouvrez Mes commandes.',
					'I can’t find order #%s in your account. Check the number (it is in the confirmation e-mail) or open My orders.',
					'Mwen pa jwenn kòmand #%s nan kont ou. Verifye nimewo a (li nan imel konfimasyon an) oswa louvri Kòmand mwen yo.' ), $number ),
				'buttons' => array(
					array( 'label' => self::t( $lang, 'Mes commandes', 'My orders', 'Kòmand mwen yo' ), 'action' => 'url', 'value' => self::orders_url() ),
					array( 'label' => self::t( $lang, 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'agent', 'value' => '' ),
				),
			) );
		}
		return self::order_card( $order, $lang );
	}

	/** The customer's last order as a full card, or null when they have none. */
	public static function last_order_reply( $lang, $uid ) {
		if ( (int) $uid < 1 || ! function_exists( 'wc_get_orders' ) ) return null;
		$orders = wc_get_orders( array( 'customer_id' => (int) $uid, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ) );
		return ( $orders && $orders[0] instanceof WC_Order ) ? self::order_card( $orders[0], $lang ) : null;
	}

	/** What the status means for the customer, in one sentence. */
	private static function status_hint( $status, $lang ) {
		switch ( $status ) {
			case 'pending':    return self::t( $lang, 'En attente du paiement : la commande part dès qu’il est validé.', 'Waiting for payment: the order goes through as soon as it is confirmed.', 'N ap tann peman an: kòmand lan ap pase depi l konfime.' );
			case 'on-hold':    return self::t( $lang, 'En vérification : l’équipe contrôle le paiement, vous serez prévenu.', 'Being checked: the team is verifying the payment, you will be notified.', 'Ekip la ap verifye peman an, n ap avèti w.' );
			case 'processing': return self::t( $lang, 'En cours de traitement : la livraison est en route.', 'Processing: delivery is on its way.', 'N ap trete l: livrezon an sou wout.' );
			case 'completed':  return self::t( $lang, 'Terminée : vos codes ou votre recharge sont dans les détails de la commande.', 'Completed: your codes or top-up are in the order details.', 'Fini: kòd ou yo oswa rechaj ou nan detay kòmand lan.' );
			case 'cancelled':  return self::t( $lang, 'Annulée. Si vous avez payé, écrivez à un agent avec ce numéro.', 'Cancelled. If you paid, write to an agent with this number.', 'Anile. Si ou te peye, ekri yon ajan ak nimewo sa a.' );
			case 'refunded':   return self::t( $lang, 'Remboursée.', 'Refunded.', 'Ranbouse.' );
			case 'failed':     return self::t( $lang, 'Paiement échoué : rien n’a été livré. Vous pouvez recommander.', 'Payment failed: nothing was delivered. You can order again.', 'Peman an echwe: anyen pa t livre. Ou ka kòmande ankò.' );
		}
		return '';
	}

	/** The order card: status and summary, link to the order, « Partager le reçu ». */
	public static function order_card( WC_Order $o, $lang ) {
		$number = (string) $o->get_order_number();
		$state  = sanitize_key( $o->get_status() );
		$status = wp_strip_all_tags( wc_get_order_status_name( $state ) );
		$total  = html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
		$date   = $o->get_date_created() ? wc_format_datetime( $o->get_date_created() ) : '';
		$paid   = wp_strip_all_tags( (string) $o->get_payment_method_title() );
		$items  = array();
		foreach ( $o->get_items() as $item ) { // names and quantities only: never item meta (Player ID, codes)
			$items[] = wp_strip_all_tags( (string) $item->get_name() ) . ' × ' . (int) $item->get_quantity();
			if ( count( $items ) >= 6 ) break;
		}
		$store = class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_name() : 'Delicat Store';
		$lines = array_filter( array(
			$store . ' — ' . sprintf( self::t( $lang, 'Reçu de la commande #%s', 'Receipt for order #%s', 'Resi kòmand #%s' ), $number ),
			self::t( $lang, 'Statut', 'Status', 'Estati' ) . ' : ' . $status,
			$date ? self::t( $lang, 'Date', 'Date', 'Dat' ) . ' : ' . $date : '',
			$items ? self::t( $lang, 'Articles', 'Items', 'Atik' ) . ' : ' . implode( ', ', $items ) : '',
			self::t( $lang, 'Total', 'Total', 'Total' ) . ' : ' . $total,
			$paid ? self::t( $lang, 'Paiement', 'Payment', 'Peman' ) . ' : ' . $paid : '',
		) );
		$chips = array();
		if ( in_array( $state, array( 'processing', 'completed' ), true ) ) $chips[] = array( 'label' => self::t( $lang, 'PIN ou recharge non reçu', 'PIN or top-up not received', 'PIN oswa rechaj pa rive' ), 'action' => 'quick', 'value' => 'pin_missing' );
		$chips[] = array( 'label' => self::t( $lang, 'Agent pour cette commande', 'Agent for this order', 'Ajan pou kòmand sa' ), 'action' => 'quick', 'value' => 'order_agent' );
		return array(
			'type' => 'order_card',
			'body' => 'order:' . $number . ':' . $state,
			'meta' => array(
				'title'   => sprintf( self::t( $lang, 'Commande #%s', 'Order #%s', 'Kòmand #%s' ), $number ),
				'empty'   => false,
				'status'  => $status,
				'state'   => $state,
				'total'   => $total,
				'date'    => $date,
				'items'   => $items,
				'payment' => $paid,
				'hint'    => self::status_hint( $state, $lang ),
				'url'     => $o->get_view_order_url(),
				'button'  => self::t( $lang, 'Voir la commande', 'View order', 'Gade kòmand lan' ),
				'share'   => array(
					'label' => self::t( $lang, 'Partager le reçu', 'Share the receipt', 'Pataje resi a' ),
					'title' => sprintf( self::t( $lang, 'Reçu #%s', 'Receipt #%s', 'Resi #%s' ), $number ),
					'text'  => implode( "\n", $lines ),
					'url'   => home_url( '/' ),
				),
				'chips'   => $chips,
			),
		);
	}

	/* ============================================================ routing */

	/**
	 * Answers that must come before the generic engine. Returns a payload, the string 'agent' (connect
	 * to a human) or 'robot' (the « bot or human? » card), or null (let the usual engine answer).
	 */
	public static function route( $body, $lang, $uid, $context = array() ) {
		$t   = self::norm( $body );
		$uid = (int) $uid;

		$number = self::order_number( $body );
		if ( '' !== $number ) return self::order_reply( $number, $lang, $uid );

		// « Es-tu un robot ? » is a question about the assistant; « parler à un humain » asks for one.
		if ( preg_match( '/\b(es tu|t es|tu es|vous etes|etes vous|are you|r u|ou se|eske ou se|se yon|is this)\b.{0,24}\b(robot|bot|robo|humain|human|machine|ia|ai|vre moun|real person)\b/', $t ) ) return 'robot';
		if ( preg_match( '/\b(agent|ajan|operateur|conseiller|service client|customer service|sipo)\b|\b(parler|parle|pale|talk|speak|discuter)\b.{0,16}\b(a|ak|to|with|avec)\b.{0,10}\b(quelqu un|un humain|une personne|yon moun|someone|somebody|a human|a person|a real|vrai|moun)\b|\b(un humain|vrai personne|yon vre moun|moun reyel|real person|a human)\b/', $t ) ) return 'agent';

		// Specific trouble before the generic guides (which answered every « pas reçu » with the
		// store-credit guide): PIN or top-up not received, invalid code, wrong Player ID, failed payment.
		if ( class_exists( 'DSA2_Training', false ) && method_exists( 'DSA2_Training', 'intent_of' ) ) {
			$intent = DSA2_Training::intent_of( $body );
			if ( in_array( $intent, array( 'topup_missing', 'pin_missing', 'code_issue', 'topup_wrong_id', 'payment_failed' ), true ) ) {
				$card = DSA2_Training::reply( $body, $lang );
				if ( $card ) return $card;
			}
		}

		// « Où est ma commande ? » from a signed-in customer: their last order.
		if ( $uid > 0 && preg_match( '/\b(ou est|ou en est|ou se trouve|kote|where is|where s|status|statut|suivre|suivi|track|tracking|estati)\b.{0,30}\b(commande|order|komand|kmand|achat)\b|\b(ma|mon|my|mes)( derniere)? (commande|order|achat)\b.{0,30}\b(ou|kote|where|arrive|statut|status|pas recu|pa rive)\b|\bkomand (mwen|m) (kote|pa rive|ki kote)\b/', $t ) ) {
			$card = self::last_order_reply( $lang, $uid );
			if ( $card ) return $card;
		}

		if ( preg_match( '/\b(revendeur|revendre|reseller|resell|revande|revann|en gros|wholesale|an gwo|gwo kantite|prix revendeur|pri revande|programme revendeur)\b/', $t ) ) {
			$card = self::reseller_card( $lang, $uid );
			if ( $card ) return $card;
		}
		if ( preg_match( '/\b(parrain\w*|referral|refer a friend|refere|inviter? (un|des|mes) ami|invite (a|my) friend|envite zanmi|kod zanmi|lien d invitation|invitation link)\b/', $t ) ) {
			$card = self::referral_card( $lang );
			if ( $card ) return $card;
		}
		if ( preg_match( '/\b(envoyer de l argent|envoyer (des|de l) (sous|argent|lajan)|transfert d argent|transferer (de l argent|a un ami)|voye lajan|voye kob|send money|transfer money|transfer to (a )?friend)\b/', $t ) ) {
			return self::send_money_card( $lang );
		}
		if ( preg_match( '/\b(pirate|hack\w*|compte vole|vole mon compte|kont mwen vole|stolen account|securite (de|du) (mon )?compte|securiser|passkey|face id|empreinte|double authentification|2fa|verification en deux|otp|code de verification)\b/', $t ) ) {
			return self::security_card( $lang, $uid );
		}
		return null;
	}

	/* ============================================================ the rest of the store */

	private static function reseller_card( $lang, $uid ) {
		if ( ! function_exists( 'dlr_on' ) || ! function_exists( 'dlr_tiers' ) ) return null;
		$url = function_exists( 'dlr_account_url' ) && $uid > 0 ? dlr_account_url() : ( function_exists( 'dlr_page_url' ) ? dlr_page_url() : home_url( '/' ) );
		if ( ! dlr_on() ) {
			return array( 'type' => 'help_card', 'body' => 'reseller:closed', 'meta' => array(
				'title' => self::t( $lang, 'Programme Revendeur', 'Reseller programme', 'Pwogram Revandè' ),
				'intro' => self::t( $lang, 'Le programme revendeur ouvre bientôt. Revenez sur cette page ou écrivez à l’équipe.', 'The reseller programme opens soon. Check back or write to the team.', 'Pwogram revandè a ap louvri byento. Tounen pita oswa ekri ekip la.' ),
			) );
		}
		$steps = array();
		foreach ( dlr_tiers() as $tier ) {
			$amount  = function_exists( 'dlr_money' ) ? dlr_money( $tier['min'] ) : number_format_i18n( $tier['min'] ) . ' HTG';
			$steps[] = $tier['label'] . ' : ' . self::t( $lang, 'dépôt minimum', 'minimum deposit', 'depo minimòm' ) . ' ' . $amount . ' / ' . self::t( $lang, 'mois', 'month', 'mwa' );
		}
		$mine = '';
		if ( $uid > 0 && function_exists( 'dlr_user_tier' ) && function_exists( 'dlr_month_progress' ) ) {
			$tier = dlr_user_tier( $uid );
			if ( '' !== $tier ) {
				$p    = dlr_month_progress( $uid );
				$mine = sprintf( self::t( $lang, 'Vous êtes %1$s. Ce mois : %2$s / %3$s.', 'You are %1$s. This month: %2$s / %3$s.', 'Ou se %1$s. Mwa sa a: %2$s / %3$s.' ), dlr_tier_label( $tier ), dlr_money( $p['paid'] ), dlr_money( $p['min'] ) );
			}
		}
		return array( 'type' => 'help_card', 'body' => 'reseller:info', 'meta' => array(
			'title'   => self::t( $lang, 'Programme Revendeur', 'Reseller programme', 'Pwogram Revandè' ),
			'intro'   => $mine ? $mine : self::t( $lang,
				'Achetez à prix revendeur et revendez à vos clients. Faites la demande ; une fois approuvée, rechargez le minimum de votre niveau par le système de recharge (pas d’envoi entre portefeuilles), puis chaque mois.',
				'Buy at reseller prices and resell to your customers. Apply; once approved, recharge your level’s minimum through the recharge system (no wallet-to-wallet transfer), then every month.',
				'Achte a pri revandè epi revann bay kliyan ou. Fè demann lan; lè yo apwouve l, rechaje minimòm nivo ou pa sistèm rechaj la (pa voye lajan ant pòtfèy), epi chak mwa.' ),
			'steps'   => $steps,
			'buttons' => array( array( 'label' => self::t( $lang, 'Programme Revendeur', 'Reseller programme', 'Pwogram Revandè' ), 'action' => 'url', 'value' => $url ) ),
		) );
	}

	private static function referral_card( $lang ) {
		if ( ! function_exists( 'delicat_ref_on' ) || ! delicat_ref_on() ) return null;
		$page = function_exists( 'delicat_ref_page_id' ) ? (int) delicat_ref_page_id() : 0;
		$url  = $page ? get_permalink( $page ) . '#parrainage' : home_url( '/' );
		return array( 'type' => 'help_card', 'body' => 'referral:info', 'meta' => array(
			'title'   => self::t( $lang, 'Parrainage', 'Invite a friend', 'Envite yon zanmi' ),
			'intro'   => self::t( $lang,
				'Partagez votre lien ou votre code personnel. Quand un ami crée son compte et paie son premier achat, vous gagnez un point ; les paliers donnent des diamants ou du crédit.',
				'Share your personal link or code. When a friend creates an account and pays a first purchase, you earn a point; milestones give diamonds or store credit.',
				'Pataje lyen oswa kòd pèsonèl ou. Lè yon zanmi kreye kont li epi li peye premye acha li, ou genyen yon pwen; chak etap bay dyaman oswa kredi.' ),
			'buttons' => array( array( 'label' => self::t( $lang, 'Mon lien de parrainage', 'My invite link', 'Lyen envitasyon mwen' ), 'action' => 'url', 'value' => $url ) ),
		) );
	}

	private static function send_money_card( $lang ) {
		$min   = class_exists( 'Delicat_Send_Money', false ) ? (float) Delicat_Send_Money::MIN_AMOUNT : 50;
		$max   = class_exists( 'Delicat_Send_Money', false ) ? (float) Delicat_Send_Money::MAX_AMOUNT : 10000;
		$fee   = class_exists( 'Delicat_Send_Money', false ) ? (float) Delicat_Send_Money::FEE_RATE * 100 : 1.5;
		$range = html_entity_decode( number_format_i18n( $min ) . ' – ' . number_format_i18n( $max ) . ' HTG', ENT_QUOTES, 'UTF-8' );
		$fee   = rtrim( rtrim( number_format( $fee, 1, ',', '' ), '0' ), ',' );
		return array( 'type' => 'help_card', 'body' => 'send_money:info', 'meta' => array(
			'title'   => self::t( $lang, 'Envoyer de l’argent', 'Send money', 'Voye lajan' ),
			'intro'   => sprintf( self::t( $lang,
				'Depuis votre portefeuille, touchez « Envoyer de l’argent », entrez le code personnel du destinataire et le montant (%1$s, frais %2$s %%), vérifiez son nom puis confirmez. Un envoi confirmé ne peut pas être annulé.',
				'From your wallet, tap “Envoyer de l’argent”, enter the recipient’s personal code and the amount (%1$s, fee %2$s%%), check their name, then confirm. A confirmed transfer cannot be undone.',
				'Nan pòtfèy ou, peze « Envoyer de l’argent », antre kòd pèsonèl moun nan ak montan an (%1$s, frè %2$s %%), verifye non l epi konfime. Yon transfè ki konfime pa ka anile.' ), $range, $fee ),
			'buttons' => array( array( 'label' => self::t( $lang, 'Mon portefeuille', 'My wallet', 'Pòtfèy mwen' ), 'action' => 'url', 'value' => 'wallet' ) ),
		) );
	}

	private static function security_card( $lang, $uid ) {
		$url = $uid > 0 && function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'identity-center' ) : self::account_url();
		return array( 'type' => 'help_card', 'body' => 'security:info', 'meta' => array(
			'title'   => self::t( $lang, 'Sécurité du compte', 'Account security', 'Sekirite kont ou' ),
			'intro'   => self::t( $lang, 'L’équipe ne vous demandera jamais votre mot de passe, un code reçu par SMS ou e-mail, ni un PIN.', 'The team will never ask for your password, a code received by SMS or e-mail, or a PIN.', 'Ekip la p ap janm mande w modpas ou, yon kòd ou resevwa pa SMS oswa imel, ni yon PIN.' ),
			'steps'   => array(
				self::t( $lang, 'Activez Passkey / Face ID dans Sécurité et appareils.', 'Turn on Passkey / Face ID in Security and devices.', 'Aktive Passkey / Face ID nan Sekirite ak aparèy.' ),
				self::t( $lang, 'Vérifiez les appareils connectés et déconnectez ceux que vous ne reconnaissez pas.', 'Check the signed-in devices and sign out the ones you don’t recognise.', 'Gade aparèy ki konekte yo epi dekonekte sa ou pa rekonèt yo.' ),
				self::t( $lang, 'Un doute ? Changez votre mot de passe et écrivez à un agent.', 'In doubt? Change your password and write to an agent.', 'Ou gen dout? Chanje modpas ou epi ekri yon ajan.' ),
			),
			'buttons' => array(
				array( 'label' => self::t( $lang, 'Sécurité et appareils', 'Security and devices', 'Sekirite ak aparèy' ), 'action' => 'url', 'value' => $url ),
				array( 'label' => self::t( $lang, 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'agent', 'value' => '' ),
			),
		) );
	}

	private static function account_url() {
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}
	private static function orders_url() {
		return function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : self::account_url();
	}
}
