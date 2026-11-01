<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retrieval layer over the bundled conversation corpus.
 *
 * v6.3.0 rebuild. The old corpus was 10 000 rows holding 169 unique questions — a 59×
 * duplicated file, English only, about products this store does not sell ("Elementor Pro
 * layout kit", "$50 Gaming PIN") with instructions that do not apply here ("check your
 * bank app", "sent via e-mail instantly"). Every unmatched message re-read and re-tokenised
 * the whole file under a 0.75 s deadline, and a French or Kreyòl customer whose question
 * did match got a French preamble glued in front of an English sentence.
 *
 * The corpus is now 1 660 unique rows, balanced across fr/en/ht, written against Delicat
 * reality: MonCash / NatCash -> TransCode -> wallet -> purchase, PINs delivered in the
 * account, physical electronics delivered in Haiti. Answers are stored in the language
 * they are served in, so nothing is translated at runtime.
 *
 * Store-specific WooCommerce data always wins; this is a fallback and an intent teacher,
 * never a source of order, price or payment facts.
 */
class DSA2_Training {
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    const MIN_SCORE = 0.58;

    public static function enabled() {
        $s = get_option( 'dap_settings', array() );
        return ! isset( $s['training_enabled'] ) || ! empty( $s['training_enabled'] );
    }

    /**
     * 6.6.1 (ported from 6.2.2): the bundled CSV is a generic English e-commerce dataset with
     * nothing about Delicat, HTG, MonCash or Natcash; its answers are opt-in, off unless the
     * setting says otherwise (a missing key means off). The intent rules stay on with
     * training_enabled.
     */
    public static function corpus_enabled() {
        $s = get_option( 'dap_settings', array() );
        return is_array( $s ) && ! empty( $s['training_corpus_enabled'] );
    }

    public static function source_path() {
        return DSA2_DIR . 'data/training-conversations.csv';
    }

    /** Cache key follows the file's mtime, so replacing the CSV invalidates the index. */
    private static function fingerprint() {
        $path = self::source_path();
        if ( ! is_readable( $path ) ) return '0';
        return substr( md5( (string) filemtime( $path ) . '|' . (string) filesize( $path ) ), 0, 10 );
    }

    public static function stats() {
        $path = self::source_path();
        if ( ! is_readable( $path ) ) {
            return array( 'available' => false, 'rows' => 0, 'size' => '0', 'languages' => array() );
        }
        $key = 'dap_train_stats_' . self::fingerprint();
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;

        $rows = 0; $langs = array();
        $fh = fopen( $path, 'r' );
        if ( $fh ) {
            fgetcsv( $fh );
            while ( ( $row = fgetcsv( $fh ) ) !== false ) {
                if ( count( $row ) < 4 ) continue;
                $rows++;
                $lang = isset( $row[4] ) ? sanitize_key( $row[4] ) : 'en';
                if ( ! isset( $langs[ $lang ] ) ) $langs[ $lang ] = 0;
                $langs[ $lang ]++;
            }
            fclose( $fh );
        }
        $out = array(
            'available' => true,
            'rows'      => (int) $rows,
            'size'      => size_format( filesize( $path ) ),
            'languages' => $langs,
        );
        set_transient( $key, $out, DAY_IN_SECONDS );
        return $out;
    }

    /**
     * Compact per-language index: answers pooled once, rows referencing them by offset.
     * About 550 rows and 80 KB per language, instead of a 10 000-line scan per message.
     */
    private static function index( $lang ) {
        $lang = in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ? $lang : 'fr';
        $key = 'dap_train_idx_' . $lang . '_' . self::fingerprint();
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['r'] ) ) return $cached;

        $path = self::source_path();
        $index = array( 'p' => array(), 'r' => array() );
        if ( ! is_readable( $path ) ) return $index;
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) return $index;

        $header = fgetcsv( $fh );
        $tagged = is_array( $header ) && count( $header ) >= 5;
        $pool = array();
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( count( $row ) < 4 ) continue;
            // A restored 4-column corpus carries no language tag; treat it as English so
            // the plugin keeps answering instead of returning an empty index.
            $row_lang = ( $tagged && isset( $row[4] ) ) ? sanitize_key( $row[4] ) : 'en';
            if ( $row_lang !== $lang ) continue;
            $question = self::normalize( $row[2] );
            if ( '' === $question ) continue;
            $answer = trim( wp_strip_all_tags( (string) $row[3] ) );
            if ( '' === $answer ) continue;
            if ( ! isset( $pool[ $answer ] ) ) {
                $pool[ $answer ] = count( $index['p'] );
                $index['p'][] = $answer;
            }
            $index['r'][] = array(
                $question,
                $pool[ $answer ],
                ( $tagged && isset( $row[5] ) ) ? sanitize_key( $row[5] ) : '',
                ( $tagged && isset( $row[6] ) ) ? sanitize_text_field( $row[6] ) : '',
            );
        }
        fclose( $fh );
        set_transient( $key, $index, self::CACHE_TTL );
        return $index;
    }

    public static function reply( $message, $lang = 'fr' ) {
        if ( ! self::enabled() ) return null;
        $normalized = self::normalize( $message );
        if ( DSA2_Compat::length( $normalized ) < 2 ) return null;

        // Answer in the language the customer wrote in, not the language of the tab
        // they happened to leave selected.
        if ( class_exists( 'DSA2_SmallTalk', false ) ) {
            $lang = DSA2_SmallTalk::detect_language( $normalized, $lang );
        }

        $entity = self::entity( $normalized );
        $intent = self::intent( $normalized );
        if ( $intent ) {
            $payload = self::intent_payload( $intent, $entity, $lang );
            if ( $payload ) return $payload;
        }
        // 6.6.1 (ported from 6.2.2): corpus answers are opt-in (corpus_enabled()). Checked
        // before the cache, so an answer cached while it was on is never served once off.
        if ( ! self::corpus_enabled() ) return null;

        $key = 'dap_train_' . $lang . '_' . md5( $normalized );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) return $cached;
        // Misses are cached too: the common case must not re-scan the corpus every time.
        if ( 'none' === $cached ) return null;

        $match = self::nearest( $normalized, $lang );
        if ( ! $match || $match['score'] < self::MIN_SCORE ) {
            set_transient( $key, 'none', 6 * HOUR_IN_SECONDS );
            return null;
        }

        $payload = array(
            'type' => 'faq_card',
            'body' => $match['answer'],
            'meta' => array(
                'title'      => self::tr( $lang, 'Réponse rapide', 'Quick answer', 'Repons rapid' ),
                'source'     => 'training_corpus',
                'intent'     => $match['intent'],
                'confidence' => round( $match['score'], 2 ),
                'chips'      => self::context_chips( $match['entity'] ? $match['entity'] : $entity, $lang ),
            ),
        );
        set_transient( $key, $payload, self::CACHE_TTL );
        return $payload;
    }

    /** Nearest question inside one language. F1 over content tokens, plus a containment bonus. */
    private static function nearest( $query, $lang ) {
        $index = self::index( $lang );
        if ( empty( $index['r'] ) ) return null;
        $qTokens = self::tokens( $query );
        if ( ! $qTokens ) return null;
        $qCount = count( $qTokens );

        $best = null;
        foreach ( $index['r'] as $row ) {
            $tokens = self::tokens( $row[0] );
            if ( ! $tokens ) continue;
            $common = count( array_intersect_key( $qTokens, $tokens ) );
            if ( ! $common ) continue;
            $precision = $common / $qCount;
            $recall    = $common / count( $tokens );
            $score = ( 2 * $precision * $recall ) / max( 0.001, $precision + $recall );
            if ( false !== strpos( $row[0], $query ) || false !== strpos( $query, $row[0] ) ) $score += 0.18;
            if ( ! $best || $score > $best['score'] ) {
                $best = array(
                    'score'  => min( 1, $score ),
                    'answer' => isset( $index['p'][ $row[1] ] ) ? $index['p'][ $row[1] ] : '',
                    'intent' => $row[2],
                    'entity' => $row[3],
                );
            }
        }
        return ( $best && '' !== $best['answer'] ) ? $best : null;
    }

    private static function intent( $t ) {
        // v6.5.0 — the automatic top-up rules come first. Delicat's headline flow is
        // "recharge automatique sur votre compte": Player ID in, currency credited in the
        // game. PIN is the smaller line (FREE FIRE PIN and the gift cards), so the PIN
        // rules must not swallow a top-up question about Free Fire, PUBG or CODM.
        $rules = array(
            'topup_missing'  => array( '/\b(recharge|rechaj\w*|top\s*up|diamant\w*|diaman\w*|dyaman\w*|diamond\w*|uc|cp|robux|jeton\w*)\b.*\b(pa rive|pa antre|pa vini|pas recu|non recu|not received|not credited|pa kredite|manke|missing|pa parèt|pa paret)\b/', '/\b(pa rive|pa antre|pas recu|not received|pa kredite)\b.*\b(recharge|rechaj\w*|diamant\w*|diaman\w*|dyaman\w*|diamond\w*|uc|cp|robux)\b/' ),
            'topup_wrong_id' => array( '/\b(move|mauvais|wrong|erreur|ere|bad)\b.*\b(player id|id|uid|pseudo|nickname|kont)\b/', '/\b(player id|id joueur|uid)\b.*\b(pa bon|move|mauvais|wrong|incorrect|erreur)\b/' ),
            'topup_no_code'  => array( '/\b(kod|code|pin)\b.*\b(free fire|freefire|pubg|call of duty|codm|efootball|blood strike|dls|flexcity|one state|roblox|robux|poppo|tiktok)\b/', '/\b(free fire|freefire|pubg|call of duty|codm|efootball|blood strike|dls|flexcity|one state|roblox|poppo|tiktok)\b.*\b(kot[ei] (kod|code)|où est mon code|where is my code)\b/' ),
            'topup_how'      => array( '/\b(kijan|comment|how|ki jan)\b.*\b(recharge|rechaj\w*|top\s*up|achte\s+(diamant|diaman|dyaman|uc|cp|robux|jeton|pyes)\w*|acheter\s+des?\s+(diamant|robux|jeton|pieces?)\w*|buy\s+(diamond|robux|uc|cp|coin)\w*)\b/', '/\b(recharge automatique|rechaj otomatik|automatic top\s*up|direct top\s*up)\b/' ),
            'order_missing'  => array( '/\b(where|ou|kote|suivre|track|status|statut|recu|received|livre|delivered)\b.*\b(order|commande|komand|kmand|achat)\b/', '/\b(order|commande|komand)\b.*\b(where|kote|status|statut|processing|traitement|attente|not received|pas recu|pa rive)\b/' ),
            'payment_failed' => array( '/\b(payment|paiement|peman|moncash|natcash|transcode)\b.*\b(failed|fail|echoue\w*|refuse|pa pase|pa mache|pa fonksyone|problem|pwoblem|issue|bloke)\b/', '/\b(cant|cannot|impossible|pa kapab|pa ka)\b.*\b(pay|payer|peye)\b/' ),
            'redeem'         => array( '/\b(how|comment|kijan|redeem|utiliser|use|activate|activer)\b.*\b(pin|code|kod|gift card|kat kado|carte cadeau)\b/', '/\b(redeem|rachat|itilize pin|utiliser mon pin|use my pin)\b/' ),
            'code_issue'     => array( '/\b(pin|code|kod)\b.*\b(invalid|invalide|used|utilise|itilize|pa mache|marche pas|ne marche|ne fonctionne|not working|doesnt work|rejete|rejected|refuse)\b/', '/\b(invalid|invalide|deja itilize|deja utilise|pa mache|rejete)\b.*\b(pin|code|kod)\b/' ),
            'pin_missing'    => array( '/\b(pa resevwa|pa jwenn|pas recu|not received|didnt receive|pa rive|manke|missing)\b.*\b(pin|code|kod)\b/', '/\b(pin|code|kod)\b.*\b(pa resevwa|pa jwenn|pas recu|non recu|not received|pa rive|manque|missing)\b/' ),
            'refund'         => array( '/\b(refunds?|rembours\w*|ranbous\w*|mistake|erreur|ere\b|wrong purchase|mauvais achat|move acha|annul\w*|anile|cancel)\b/' ),
            'price'          => array( '/\b(price|prix|pri|tarif|combien|how much|konbyen|koute|koute konbyen)\b/' ),
            'bulk_reseller'  => array( '/\b(bulk|wholesale|reseller|revendeur|revande|en gros|an gwo|gwo kantite)\b/' ),
            'expiry'         => array( '/\b(expire|expiration|validite|valable|ekspire)\b/' ),
            'legitimate'     => array( '/\b(legit|legitimate|authentic|otantik|original|fiable|securise|trusted|arnaque|eskwokri|scam)\b/' ),
            'delivery'       => array( '/\b(livraison|livrezon|delivery|livrez|deliver|shipping)\b/' ),
            'warranty'       => array( '/\b(garantie|garanti|warranty|defectueux|defektye|defective)\b/' ),
            'player_id'      => array( '/\b(player id|id joueur|identifiant joueur|uid|id jwe)\b/' ),
            'ff_region'      => array( '/\b(free fire|freefire|ff)\b.*\b(region|rejyon|latam|br\b|europe|mena|\bid\b|serveur|server|kilès|kiles|ki sa pou|quelle|which|diferans|difference)\b/', '/\b(region|rejyon|serveur|server)\b.*\b(free fire|freefire|ff)\b/' ),
        );
        foreach ( $rules as $intent => $patterns ) {
            foreach ( $patterns as $pattern ) if ( preg_match( $pattern, $t ) ) return $intent;
        }
        return '';
    }

    /**
     * Product recognition, rebuilt against the live delicastoreha.com catalogue
     * (43 products, verified 2026-09-07).
     *
     * The previous list knew 18 of them and invented four the store has never sold —
     * Spotify, smartwatches, smartphones and laptops — so "Livraison pour un laptop ?"
     * produced a confident answer about a product that does not exist. Everything is
     * matched on word boundaries now: the old length>=4 shortcut used strpos(), which
     * finds "wise" inside "otherwise" and "meru" inside "demeure".
     */
    private static function entity( $t ) {
        $entities = array(
            // Games
            'Free Fire'           => array( 'free fire', 'freefire', 'ff', 'diamant', 'diamants', 'dyaman', 'diamond', 'diamonds' ),
            'Blood Strike'        => array( 'blood strike', 'bloodstrike' ),
            'Call of Duty Mobile' => array( 'call of duty', 'cod mobile', 'codm', 'cod' ),
            'DLS 2026'            => array( 'dls', 'dream league' ),
            'eFootball'           => array( 'efootball', 'e football', 'pes' ),
            'FlexCity RP'         => array( 'flexcity', 'flex city', 'flex pass' ),
            'ONE STATE RP'        => array( 'one state', 'onestate' ),
            'PUBG Mobile'         => array( 'pubg' ),
            'Roblox'              => array( 'roblox', 'robux' ),
            'Controller X3'       => array( 'controller', 'controlleur', 'manette' ),
            // Gift cards
            'Amazon Gift Card'    => array( 'amazon' ),
            'Apple Gift Card'     => array( 'apple', 'itunes', 'app store' ),
            'Discord Nitro'       => array( 'discord', 'nitro' ),
            'Nintendo'            => array( 'nintendo', 'switch' ),
            'PlayStation'         => array( 'playstation', 'psn', 'ps4', 'ps5' ),
            'Razer Gold'          => array( 'razer' ),
            'TikTok Pieces'       => array( 'tiktok', 'tik tok' ),
            'Xbox'                => array( 'xbox' ),
            // Subscriptions
            'Netflix'             => array( 'netflix' ),
            'CapCut'              => array( 'capcut', 'cap cut' ),
            'Crunchyroll'         => array( 'crunchyroll', 'crunchy' ),
            'Disney Plus'         => array( 'disney' ),
            'HBO MAX'             => array( 'hbo' ),
            'Prime Video'         => array( 'prime video', 'prime' ),
            'Surfshark'           => array( 'surfshark', 'vpn' ),
            // 6.6.3: the « Exchange » / « Finance » products (Binance, Meru, PayPal, USDT, WISE)
            // are no longer recognised. They are sold on the website only (Google Play: no
            // financial services in the app), and an answer naming them would reach the app.
            // Social
            'Poppo Live'          => array( 'poppo' ),
            // Generic and payment rails
            'Gift Card'           => array( 'gift card', 'carte cadeau', 'kat kado' ),
            'Natcash Auto (TC)'   => array( 'natcash', 'transcode' ),
            'MonCash'             => array( 'moncash' ),
        );
        foreach ( $entities as $name => $aliases ) {
            foreach ( $aliases as $alias ) {
                if ( preg_match( '/\b' . preg_quote( $alias, '/' ) . '\b/', $t ) ) return $name;
            }
        }
        return '';
    }

    private static function intent_payload( $intent, $entity, $lang ) {
        $product = $entity ? $entity : self::tr( $lang, 'ce produit', 'this product', 'pwodwi sa a' );
        $agent = array( 'label' => self::tr( $lang, 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'quick', 'value' => 'agent' );
        $order = array( 'label' => self::tr( $lang, 'Ma commande', 'My order', 'Kòmand mwen' ), 'action' => 'quick', 'value' => 'last_order' );

        $cards = array(
            'order_missing' => array(
                self::tr( $lang, 'Suivi de commande', 'Order tracking', 'Swivi kòmand' ),
                self::tr( $lang,
                    'Je peux vérifier votre commande ' . $product . '. Ouvrez « Ma dernière commande » si vous êtes connecté, ou donnez le numéro de commande et l’e-mail/WhatsApp utilisé lors de l’achat. Les produits numériques apparaissent dans votre compte, pas par e-mail.',
                    'I can check your ' . $product . ' order. Use “My last order” when signed in, or give the order number and the e-mail/WhatsApp used at checkout. Digital products appear in your account, not by e-mail.',
                    'Mwen ka verifye kòmand ' . $product . ' ou a. Louvri « Dènye kòmand mwen » si ou konekte, oswa bay nimewo kòmand lan ak imel/WhatsApp ou te itilize a. Pwodwi dijital yo parèt nan kont ou, pa nan imel.' ),
                array( $order, $agent ) ),
            'payment_failed' => array(
                self::tr( $lang, 'Paiement non validé', 'Payment not completed', 'Peman pa valide' ),
                self::tr( $lang,
                    'Pour un paiement ' . $product . ' échoué, ne recommencez pas plusieurs fois. Vérifiez le SMS MonCash/NatCash : s’il contient un TransCode, entrez-le sur la page de recharge pour l’ajouter à votre crédit boutique. Si le montant a été débité sans TransCode, envoyez la capture à un agent.',
                    'For a failed ' . $product . ' payment, don’t retry repeatedly. Check the MonCash/NatCash SMS: if it has a TransCode, enter it on the top-up page to add it to your store credit. If the amount was debited with no TransCode, send the screenshot to an agent.',
                    'Pou yon peman ' . $product . ' ki echwe, pa eseye plizyè fwa. Tcheke SMS MonCash/NatCash la: si li gen yon TransCode, antre l sou paj rechaj la pou ajoute l nan kredi boutik ou. Si yo retire montan an san TransCode, voye foto a bay yon ajan.' ),
                array( array( 'label' => 'Natcash Auto (TC)', 'action' => 'quick', 'value' => 'natcash' ), $agent ) ),
            'redeem' => array(
                self::tr( $lang, 'Utiliser le PIN', 'Redeem the PIN', 'Itilize PIN lan' ),
                self::tr( $lang,
                    'Pour utiliser votre ' . $product . ' : connectez-vous, ouvrez vos PIN livrés dans votre compte, copiez le code, saisissez votre Player ID si demandé, vérifiez puis confirmez. Ne partagez jamais le PIN avant de l’utiliser.',
                    'To redeem your ' . $product . ': sign in, open your delivered PINs in your account, copy the code, enter your Player ID if asked, check the details and confirm. Never share the PIN before redeeming it.',
                    'Pou itilize ' . $product . ' ou: konekte, louvri PIN ou resevwa yo nan kont ou, kopye kòd la, antre Player ID ou si yo mande l, verifye epi konfime. Pa janm pataje PIN lan anvan ou itilize l.' ),
                array( array( 'label' => self::tr( $lang, 'Voir mon PIN', 'View my PIN', 'Gade PIN mwen' ), 'action' => 'quick', 'value' => 'pin' ),
                       array( 'label' => self::tr( $lang, 'Tutoriel étape par étape', 'Step-by-step tutorial', 'Gid etap pa etap' ), 'action' => 'guide', 'value' => 'pin' ) ) ),
            'code_issue' => array(
                self::tr( $lang, 'Problème de PIN', 'PIN problem', 'Pwoblèm PIN' ),
                self::tr( $lang,
                    'Si le PIN ' . $product . ' est invalide ou déjà utilisé, ne publiez pas le code. Vérifiez les caractères qui se ressemblent (0/O, 1/I), la région et la plateforme, puis envoyez le numéro de commande et une capture de l’erreur à un agent.',
                    'If the ' . $product . ' PIN is invalid or already used, don’t post the code. Check look-alike characters (0/O, 1/I), the region and the platform, then send the order number and an error screenshot to an agent.',
                    'Si PIN ' . $product . ' la pa valab oswa li deja itilize, pa mete kòd la deyò. Tcheke karaktè ki sanble yo (0/O, 1/I), rejyon an ak platfòm lan, epi voye nimewo kòmand lan ak yon foto erè a bay yon ajan.' ),
                array( $agent ) ),
            'refund' => array(
                self::tr( $lang, 'Demande de remboursement', 'Refund request', 'Demann ranbousman' ),
                self::tr( $lang,
                    'Les produits numériques sont définitifs une fois le code affiché, utilisé ou livré. Pour ' . $product . ', contactez un agent immédiatement avec le numéro de commande : tant que le code n’a pas été révélé, une annulation ou un remplacement reste possible.',
                    'Digital products are final once the code is revealed, used or delivered. For ' . $product . ', contact an agent immediately with the order number: as long as the code hasn’t been revealed, cancellation or replacement is still possible.',
                    'Pwodwi dijital yo final yon fwa kòd la parèt, itilize oswa livre. Pou ' . $product . ', kontakte yon ajan touswit ak nimewo kòmand lan: toutotan kòd la poko parèt, yon anilasyon oswa yon ranplasman toujou posib.' ),
                array( $agent ) ),
            'price' => array(
                self::tr( $lang, 'Prix actuel', 'Current price', 'Pri aktyèl' ),
                self::tr( $lang,
                    'Le prix de ' . $product . ' dépend du pack et des promotions en cours. Ouvrez la fiche produit pour le prix WooCommerce actuel et le stock en direct.',
                    'The price of ' . $product . ' depends on the pack and current promotions. Open the product page for the live WooCommerce price and stock.',
                    'Pri ' . $product . ' depann de pak la ak pwomosyon yo. Louvri fich pwodwi a pou pri WooCommerce aktyèl la ak stock an dirèk.' ),
                array( array( 'label' => self::tr( $lang, 'Voir les produits', 'Browse products', 'Gade pwodwi yo' ), 'action' => 'quick', 'value' => 'products' ) ) ),
            'bulk_reseller' => array(
                self::tr( $lang, 'Revendeur / gros', 'Reseller / bulk', 'Revandè / an gwo' ),
                self::tr( $lang,
                    'Pour ' . $product . ' en quantité, {store} a un programme revendeur avec des tarifs dégressifs. Un agent confirme le stock, le dépôt initial et les conditions avant tout paiement.',
                    'For ' . $product . ' in volume, {store} runs a reseller programme with tiered pricing. An agent confirms stock, the initial deposit and the terms before any payment.',
                    'Pou ' . $product . ' an kantite, {store} gen yon pwogram revandè ak pri ki desann. Yon ajan ap konfime stock, depo inisyal la ak kondisyon yo anvan okenn peman.' ),
                array( $agent ) ),
            'expiry' => array(
                self::tr( $lang, 'Validité', 'Validity', 'Validite' ),
                self::tr( $lang,
                    'La validité de ' . $product . ' dépend du produit, de la région et du fournisseur. La fiche produit et les instructions de livraison font foi ; si aucune durée n’est indiquée, utilisez le code rapidement.',
                    $product . ' validity depends on the product, region and supplier. The product page and delivery instructions are authoritative; if no duration is shown, redeem the code soon.',
                    'Validite ' . $product . ' depann de pwodwi a, rejyon an ak founisè a. Fich pwodwi a ak enstriksyon livrezon yo se referans; si pa gen dire, itilize kòd la vit.' ),
                array( $agent ) ),
            'legitimate' => array(
                self::tr( $lang, 'Sécurité et authenticité', 'Safety and authenticity', 'Sekirite ak otantisite' ),
                self::tr( $lang,
                    'Les produits ' . $product . ' sont livrés via le système de commande officiel de {store}, avec un numéro traçable dans votre compte. Ne communiquez jamais mot de passe, OTP ou PIN à qui que ce soit — même à quelqu’un qui dit travailler pour nous.',
                    $product . ' products are delivered through {store}’s official order system, with a number traceable in your account. Never share a password, OTP or PIN with anyone — including someone claiming to work for us.',
                    'Pwodwi ' . $product . ' yo livre atravè sistèm kòmand ofisyèl {store} la, ak yon nimewo ou ka swiv nan kont ou. Pa janm bay modpas, OTP oswa PIN bay pèsonn — menm yon moun ki di l ap travay pou nou.' ),
                array( $agent ) ),
            'delivery' => array(
                self::tr( $lang, 'Livraison', 'Delivery', 'Livrezon' ),
                self::tr( $lang,
                    'Presque tout chez Delicat est numérique et arrive dans votre compte en quelques secondes — il n’y a rien à expédier. La manette Controller X3 est notre seul article physique : donnez votre commune et un agent confirme le délai et les frais avant paiement.',
                    'Almost everything at Delicat is digital and lands in your account within seconds — there is nothing to ship. The Controller X3 is our only physical item: give your commune and an agent confirms the delay and fee before payment.',
                    'Prèske tout bagay nan Delicat se dijital epi li rive nan kont ou nan kèk segonn — pa gen anyen pou voye. Controller X3 la se sèl atik fizik nou: bay komin ou epi yon ajan ap konfime delè a ak frè yo anvan peman.' ),
                array( $agent ) ),
            'warranty' => array(
                self::tr( $lang, 'Garantie', 'Warranty', 'Garanti' ),
                self::tr( $lang,
                    'L’état et la garantie de ' . $product . ' sont indiqués sur la fiche produit. En cas de défaut à la réception, envoyez une photo et votre numéro de commande à un agent le jour même pour un remplacement.',
                    'The condition and warranty for ' . $product . ' are stated on the product page. If it arrives defective, send a photo and your order number to an agent the same day for a replacement.',
                    'Kondisyon ak garanti ' . $product . ' make sou fich pwodwi a. Si li rive ak yon defo, voye yon foto ak nimewo kòmand ou bay yon ajan menm jou a pou yon ranplasman.' ),
                array( $agent ) ),
            'pin_missing' => array(
                self::tr( $lang, 'PIN non reçu', 'PIN not received', 'PIN pa rive' ),
                self::tr( $lang,
                    'Vos PIN sont livrés dans votre compte, jamais par e-mail : ouvrez « Mon compte » puis la section de vos PIN, connecté avec le compte utilisé pour l’achat. Si la commande est payée et que le PIN n’y est pas, donnez-moi le numéro de commande et un agent le libère manuellement.',
                    'Your PINs are delivered inside your account, never by e-mail: open “My account” then your PIN section, signed in with the account used for the purchase. If the order is paid and the PIN isn’t there, give me the order number and an agent releases it manually.',
                    'PIN ou yo livre nan kont ou, pa janm nan imel: louvri « Kont mwen » epi seksyon PIN ou yo, konekte ak kont ou te itilize a. Si kòmand lan peye epi PIN lan pa la, ban m nimewo kòmand lan epi yon ajan ap lage l alamen.' ),
                array( array( 'label' => self::tr( $lang, 'Voir mon PIN', 'View my PIN', 'Gade PIN mwen' ), 'action' => 'quick', 'value' => 'pin' ), $agent ) ),
            'ff_region' => array(
                self::tr( $lang, 'Free Fire : quelle région ?', 'Free Fire: which region?', 'Free Fire: ki rejyon?' ),
                self::tr( $lang,
                    'Free Fire est vendu ici en plusieurs versions régionales — LATAM, BR, EUROPE, ID et MENA — plus le FREE FIRE PIN. La recharge doit correspondre à la région de votre compte de jeu, sinon elle échoue. Donnez-moi votre Player ID : un agent confirme la bonne région avant que vous payiez.',
                    'Free Fire is sold here in several regional builds — LATAM, BR, EUROPE, ID and MENA — plus the FREE FIRE PIN. The top-up has to match your game account\'s region or it fails. Send me your Player ID and an agent confirms the right one before you pay.',
                    'Free Fire vann isit la nan plizyè vèsyon rejyonal — LATAM, BR, EUROPE, ID ak MENA — plis FREE FIRE PIN an. Rechaj la dwe koresponn ak rejyon kont jwèt ou, sinon li p ap pase. Ban m Player ID ou epi yon ajan ap konfime bon an anvan ou peye.' ),
                array( array( 'label' => self::tr( $lang, 'Voir les packs Free Fire', 'See Free Fire packs', 'Gade pak Free Fire yo' ), 'action' => 'quick', 'value' => 'products' ), $agent ) ),
            'topup_no_code' => array(
                self::tr( $lang, 'Pas de code pour ce jeu', 'No code for this game', 'Pa gen kòd pou jwèt sa a' ),
                self::tr( $lang,
                    'Pour ' . $product . ', il n’y a pas de code à recevoir : la recharge est créditée directement sur votre compte de jeu à partir du Player ID. Fermez et relancez le jeu pour voir le solde. Les codes concernent les cartes cadeaux et le produit FREE FIRE PIN, qui sont vendus séparément — si c’est celui-là que vous avez acheté, il est dans « Mon compte ».',
                    'For ' . $product . ' there is no code to receive: the top-up is credited straight to your game account from the Player ID. Close and reopen the game to see the balance. Codes apply to the gift cards and to the separate FREE FIRE PIN product — if that is what you bought, it is in "My account".',
                    'Pou ' . $product . ', pa gen kòd pou resevwa: yo kredite rechaj la dirèk nan kont jwèt ou apati Player ID a. Fèmen epi rilanse jwèt la pou wè balans lan. Kòd yo se pou kat kado yo ak pwodwi FREE FIRE PIN an, ki vann apa — si se sa ou te achte, li nan « Kont mwen ».' ),
                array( array( 'label' => self::tr( $lang, 'Mes PIN', 'My PINs', 'PIN mwen yo' ), 'action' => 'quick', 'value' => 'pin' ), $agent ) ),
            'topup_how' => array(
                self::tr( $lang, 'Recharge automatique', 'Automatic top-up', 'Rechaj otomatik' ),
                self::tr( $lang,
                    'Pour ' . $product . ', tout est automatique : choisissez le pack, entrez votre Player ID, vérifiez que le pseudo affiché est bien le vôtre, puis payez avec votre crédit boutique Delicat. La recharge arrive dans le jeu en moins de 60 secondes — il n’y a aucun code à saisir. Nous ne demandons jamais votre mot de passe.',
                    'For ' . $product . ' everything is automatic: pick the pack, enter your Player ID, check the nickname shown is yours, then pay with your Delicat store credit. The top-up lands in the game in under 60 seconds — there is no code to enter. We never ask for your password.',
                    'Pou ' . $product . ', tout bagay otomatik: chwazi pak la, antre Player ID ou, verifye non ki parèt la se pa w, epi peye ak kredi boutik Delicat ou. Rechaj la rive nan jwèt la nan mwens pase 60 segonn — pa gen okenn kòd pou antre. Nou pa janm mande modpas ou.' ),
                array( array( 'label' => self::tr( $lang, 'Voir les packs', 'See the packs', 'Gade pak yo' ), 'action' => 'quick', 'value' => 'products' ),
                       array( 'label' => self::tr( $lang, 'Où est mon Player ID ?', 'Where is my Player ID?', 'Kote Player ID mwen?' ), 'action' => 'quick', 'value' => 'player_id_help' ) ) ),
            'topup_missing' => array(
                self::tr( $lang, 'Recharge non reçue', 'Top-up not received', 'Rechaj pa rive' ),
                self::tr( $lang,
                    'Ne repassez pas de commande. Fermez et relancez complètement le jeu : le solde ne se rafraîchit pas toujours en direct. Si après cela ' . $product . ' n’est toujours pas crédité, envoyez-moi le numéro de commande et le Player ID utilisé — un agent vérifie la transaction chez le fournisseur et la relance ou la rembourse.',
                    'Don’t place the order again. Close and fully restart the game first: the balance does not always refresh live. If ' . $product . ' still isn’t credited after that, send me the order number and the Player ID you used — an agent checks the transaction with the supplier and re-sends or refunds it.',
                    'Pa pase kòmand lan ankò. Fèmen epi rilanse jwèt la nèt anvan: balans lan pa toujou rafrechi an dirèk. Si apre sa ' . $product . ' toujou pa kredite, voye ban mwen nimewo kòmand lan ak Player ID ou te itilize a — yon ajan ap verifye tranzaksyon an ak founisè a epi voye l ankò oswa ranbouse l.' ),
                array( $order, $agent ) ),
            'topup_wrong_id' => array(
                self::tr( $lang, 'Mauvais Player ID', 'Wrong Player ID', 'Move Player ID' ),
                self::tr( $lang,
                    'Écrivez-nous tout de suite : tant que la recharge n’est pas partie chez le fournisseur, un agent peut encore corriger l’ID. Une fois la recharge envoyée sur un compte, elle ne peut plus être reprise — c’est pourquoi le pseudo s’affiche avant le paiement, pour être vérifié.',
                    'Write to us immediately: as long as the top-up hasn’t gone to the supplier, an agent can still correct the ID. Once a top-up has landed on an account it cannot be pulled back — that is why the nickname is shown before payment, so it can be checked.',
                    'Ekri nou touswit: toutotan rechaj la poko pati kay founisè a, yon ajan ka toujou korije ID a. Yon fwa yon rechaj rive sou yon kont, yo pa ka retire l — se poutèt sa non an parèt anvan peman an, pou w ka verifye l.' ),
                array( $agent ) ),
            'player_id' => array(
                self::tr( $lang, 'Player ID', 'Player ID', 'Player ID' ),
                self::tr( $lang,
                    'Où le trouver : ' . self::id_hint( $entity, $lang ) . ' Copiez-le exactement, sans espace, et vérifiez que le pseudo affiché correspond au vôtre avant de payer. Si une commande est partie avec un mauvais ID, écrivez tout de suite — tant que la recharge n’est pas envoyée, un agent peut corriger.',
                    'Where to find it: ' . self::id_hint( $entity, $lang ) . ' Copy it exactly, no spaces, and check the nickname shown matches yours before paying. If an order went out with the wrong ID, write immediately — as long as the top-up hasn’t been sent, an agent can fix it.',
                    'Kote pou jwenn li: ' . self::id_hint( $entity, $lang ) . ' Kopye l egzakteman, san espas, epi verifye non ki parèt la se pa w anvan ou peye. Si yon kòmand pati ak yon move ID, ekri touswit — toutotan rechaj la poko voye, yon ajan ka korije l.' ),
                array( $agent ) ),
        );

        if ( ! isset( $cards[ $intent ] ) ) return null;
        $card = $cards[ $intent ];
        $store = class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_name() : 'Delicat Store';
        return array(
            'type' => 'faq_card',
            'body' => str_replace( '{store}', $store, $card[1] ),
            'meta' => array( 'title' => $card[0], 'source' => 'intent_engine', 'intent' => $intent, 'chips' => $card[2] ),
        );
    }


    /**
     * Where each game shows the identifier the top-up needs.
     *
     * Only the games whose profile layout is well established are named. Anything else
     * is pointed at the field on the product page rather than guessed at, because a
     * confident wrong instruction here sends a customer's diamonds to a stranger.
     */
    private static function id_hint( $entity, $lang ) {
        $hints = array(
            'Free Fire' => array(
                'dans le lobby Free Fire, touchez votre avatar en haut à gauche — le Player ID numérique est sous votre pseudo.',
                'in the Free Fire lobby, tap your avatar top-left — the numeric Player ID sits under your nickname.',
                'nan lobby Free Fire a, peze avatar ou anwo agoch — Player ID nimerik la anba non ou.' ),
            'PUBG Mobile' => array(
                'ouvrez votre profil PUBG Mobile : le Character ID numérique est affiché sous votre pseudo.',
                'open your PUBG Mobile profile: the numeric Character ID is shown under your nickname.',
                'louvri pwofil PUBG Mobile ou: Character ID nimerik la parèt anba non ou.' ),
            'Call of Duty Mobile' => array(
                'dans Call of Duty Mobile, ouvrez votre profil : l’UID (Open ID) y est affiché.',
                'in Call of Duty Mobile, open your profile: the UID (Open ID) is shown there.',
                'nan Call of Duty Mobile, louvri pwofil ou: UID (Open ID) parèt la.' ),
            'Roblox' => array(
                'pour Roblox, ce n’est pas un numéro mais votre nom d’utilisateur exact.',
                'for Roblox it is not a number but your exact username.',
                'pou Roblox, se pa yon nimewo men non itilizatè egzak ou.' ),
            'Poppo Live' => array(
                'ouvrez votre profil Poppo Live : l’ID utilisateur y est affiché.',
                'open your Poppo Live profile: the user ID is shown there.',
                'louvri pwofil Poppo Live ou: ID itilizatè a parèt la.' ),
        );
        if ( isset( $hints[ $entity ] ) ) return self::tr( $lang, $hints[ $entity ][0], $hints[ $entity ][1], $hints[ $entity ][2] );
        return self::tr( $lang,
            'le champ demandé est indiqué sur la fiche du produit, juste avant le paiement.',
            'the field you need is named on the product page, right before payment.',
            'jaden yo mande a make sou fich pwodwi a, jis anvan peman an.' );
    }

    private static function tokens( $text ) {
        static $stop = null;
        if ( null === $stop ) {
            $stop = array_flip( array(
                'the','a','an','is','are','do','does','can','i','my','for','to','of','and','or','it','this','that','you','your',
                'le','la','les','un','une','des','de','du','je','mon','ma','mes','est','ce','sa','et','ou','en','au','aux','vous',
                'pou','mwen','yon','nan','ak','ki','yo','se','ap','sou','pa','li','nou',
            ) );
        }
        $parts = preg_split( '/[^a-z0-9]+/', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
        $out = array();
        foreach ( $parts as $p ) if ( strlen( $p ) > 1 && ! isset( $stop[ $p ] ) ) $out[ $p ] = 1;
        return $out;
    }

    private static function normalize( $text ) {
        $text = remove_accents( strtolower( wp_strip_all_tags( (string) $text ) ) );
        return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9\s$#-]+/', ' ', $text ) ) );
    }

    private static function context_chips( $entity, $lang ) {
        $chips = array();
        if ( in_array( $entity, array( 'Free Fire', 'Roblox', 'PUBG Mobile', 'Call of Duty Mobile', 'FC Mobile', 'eFootball' ), true ) ) {
            $chips[] = array( 'label' => self::tr( $lang, 'Voir mon PIN', 'View my PIN', 'Gade PIN mwen' ), 'action' => 'quick', 'value' => 'pin' );
        }
        $chips[] = array( 'label' => self::tr( $lang, 'Ma commande', 'My order', 'Kòmand mwen' ), 'action' => 'quick', 'value' => 'last_order' );
        $chips[] = array( 'label' => self::tr( $lang, 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'quick', 'value' => 'agent' );
        return $chips;
    }

    private static function tr( $lang, $fr, $en, $ht ) { return 'en' === $lang ? $en : ( 'ht' === $lang ? $ht : $fr ); }
}
