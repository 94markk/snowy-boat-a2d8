<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Delicat Assistant Pro — conversational layer (v6.3.0).
 *
 * Everything a customer says that is not a support request: greetings, "sak pase",
 * thanks, apologies, goodbyes, compliments, frustration, "ou la?", "tann", jokes,
 * introductions, language switches. Three languages, several wordings per intent so
 * the same phrase never gets the same canned sentence twice in a row.
 *
 * Previously ~140 lines wedged inside DSA2_Data with ten fixed shapes, one wording
 * each, and a hard bail-out: any message containing a support word skipped small talk
 * entirely, so "Bonswa, kote kòmand mwen an?" was answered without a hello. This class
 * separates the two decisions — what the customer is *saying socially* and what they
 * are *asking for* — so a greeting in front of a real question is answered as both.
 *
 * The intent table is generated from build/intents.py together with the CSV corpus,
 * so the engine and the training data can never drift apart.
 */
class DSA2_SmallTalk {

    const VERSION = '1.0.0';

    /** Language a message is written in, scored on the message itself, not the UI tab. */
    private static $lang_words = array(
        'ht' => array( 'sak','sakap','sakpase','kijan','kouman','koman','mwen','mwenm','map','nap','mesi','ede','bezwen','byen','bonswa','bonjou','orevwa','kisa','poukisa','tanpri','wi','anyen','tann','konprann','pwodwi','komand','rele','jodi','kounye','gade','bous','peman','ou','w','pa','ki','se','yon','nan','ap','fet','anfom','boule','padon','apresye' ),
        'fr' => array( 'bonjour','bonsoir','salut','merci','comment','allez','vous','ca','aide','compris','revoir','oui','bien','quoi','pourquoi','svp','tres','suis','je','mon','ma','est','une','pour','avec','dans','commande','produit','paiement','solde','besoin','pardon','desole','attends','journee','soiree' ),
        'en' => array( 'hello','hi','hey','thanks','thank','how','good','help','bye','yes','fine','understand','the','you','your','my','is','are','what','where','when','order','payment','balance','please','sorry','wait','morning','evening','name','can','do' ),
    );

    /**
     * Words that mean the customer wants something from the store, not conversation.
     * Their presence does not cancel small talk any more — it only decides whether the
     * social part is the whole message (pure) or a lead-in to a real request (blend).
     */
    const SUPPORT_WORDS = '/\b(pin|kod|code|solde|balans|bous|commande|order|komand|achat|achte|acheter|buy|produit|pwodwi|product|recharge|rechaje|rechaj|top\s*up|moncash|natcash|transcode|paiement|peman|payment|payer|peye|rembours|refund|ranbous|player\s*id|diamant|diamond|dyaman|robux|free\s*fire|roblox|pubg|efootball|fc\s*mobile|call\s*of\s*duty|netflix|spotify|gift\s*card|kat\s*kado|livraison|delivery|livrezon|stock|prix|price|pri|tarif|montre|smartwatch|telefon|smartphone|laptop|ordinateur|garantie|warranty|revandè|revendeur|reseller|compte|kont|account|mot\s*de\s*passe|modpas|password|facture|invoice|agent|ajan|whatsapp)\b/u';

    /**
     * 7.1.0: business words by their stem (plurals, verb forms) and the question words that make a
     * message a real request: « Bonjour, je n'ai pas reçu mes diamants » is not a greeting.
     */
    const SUPPORT_STEMS = '/\\b(command|order|komand|kmand|diaman|diamond|dyaman|codes?\\b|kod\\b|pin\\b|produit|pwodwi|product|recharg|rechaj|achet|achat|achte|buy|pay\\b|paie|payer|peye|peman|paiement|solde|balans|prix|price|pri\\b|tarif|rembours|refund|ranbous|livr|deliver|compte|kont\\b|account|password|modpas|revend|reseller|revande|parrain|referral|bonus|cadeau|kado|gift|wallet|portefeuille|moncash|natcash|transcode|player|joueur|jwe\\b|robux|combien|konbyen|how much|quand|kile\\b|when\\b|pourquoi|poukisa|why\\b|kote\\b|where\\b|disponible|available|delai|recu\\b|resevwa|pa rive|arrive|probleme|pwoblem|problem|erreur|error|bloque|bloke|rejete|refuse|depuis|depi\\b)/';

    /** Social replies that only make sense for a short message (« ça va ? », « bye », « attends »). */
    const SHORT_ONLY = array( 'how_are_you', 'im_fine', 'im_not_fine', 'goodbye', 'good_day', 'wait', 'yes', 'no', 'ack', 'laughing', 'nothing', 'busy', 'happy' );
    /** Words that carry no request (greetings, politeness, address) when counting what a message says. */
    const FILLER = array( 'bonjour', 'bonjou', 'bonsoir', 'bonswa', 'salut', 'hello', 'hi', 'hey', 'allo', 'alo', 'coucou', 'yo', 'bro', 'frere', 'fre', 'cheri', 'cherie', 'monsieur', 'madame', 'mesye', 'madanm', 'svp', 'stp', 'please', 'pls', 'tanpri', 'la', 'a', 'toi', 'vous', 'ou', 'w', 'you', 'the', 'et', 'and', 'epi' );
    /** Never stored as the customer's name (« m rele pou kòmand mwen », « call me back »). */
    const NOT_A_NAME = array( 'pou', 'pa', 'pas', 'not', 'back', 'the', 'a', 'an', 'ou', 'w', 'nan', 'ak', 'de', 'le', 'la', 'les', 'mon', 'ma', 'sur', 'pour', 'bien', 'ici', 'tout', 'sa', 'se', 'ki', 'yon', 'mwen', 'moi', 'me', 'here', 'now', 'on', 'in', 'about', 'because', 'paske', 'parce', 'kounye', 'maintenant', 'agent', 'ajan', 'client', 'kliyan' );

    /** Emoji only: no letters, no digits, and at least one non-ASCII glyph.
     *  Plain "???" or "..." is punctuation, not an emoji, and belongs to unclear_short. */
    const EMOJI_ONLY   = '/^[^\p{L}\p{N}]+$/u';
    const HAS_NON_ASCII = '/[^\x00-\x7F]/';


    /** Cheap keyword map for fuzzy rescue on very short, misspelled messages. */
    private static $fuzzy = array(
        'bonjou' => 'greeting', 'bonjour' => 'greeting', 'bonswa' => 'greeting', 'bonsoir' => 'greeting',
        'salut' => 'greeting', 'hello' => 'greeting', 'allo' => 'greeting', 'coucou' => 'greeting',
        'mesi' => 'thanks', 'merci' => 'thanks', 'thanks' => 'thanks',
        'orevwa' => 'goodbye', 'aurevoir' => 'goodbye', 'goodbye' => 'goodbye',
        'sakpase' => 'how_are_you', 'kijanouye' => 'how_are_you', 'cava' => 'how_are_you',
        'sava' => 'how_are_you', 'howareyou' => 'how_are_you',
        'padon' => 'apology', 'pardon' => 'apology', 'sorry' => 'apology',
        'tann' => 'wait', 'attends' => 'wait',
        'oula' => 'are_you_there', 'anyonethere' => 'are_you_there',
    );

    public static function enabled() {
        $s = get_option( 'dap_settings', array() );
        return ! isset( $s['smalltalk_enabled'] ) || ! empty( $s['smalltalk_enabled'] );
    }

    /** Accent-free, punctuation-free, single-spaced. Kreyòl ò/è/à fold to o/e/a. */
    public static function normalize( $text ) {
        $t = wp_strip_all_tags( (string) $text );
        $t = str_replace( array( '’', '‘', '`', '´' ), "'", $t );
        $t = function_exists( 'remove_accents' ) ? remove_accents( $t ) : $t;
        $t = strtolower( $t );
        $t = preg_replace( '/[^a-z0-9\s\']+/u', ' ', $t );
        $t = str_replace( "'", ' ', $t );
        return trim( preg_replace( '/\s+/', ' ', $t ) );
    }

    /** Which of fr/en/ht the customer actually typed in. Falls back to the UI language. */
    public static function detect_language( $normalized, $fallback = 'fr' ) {
        $words = preg_split( '/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $words ) return $fallback;
        $score = array( 'fr' => 0, 'en' => 0, 'ht' => 0 );
        foreach ( $words as $w ) {
            foreach ( self::$lang_words as $lang => $list ) {
                if ( in_array( $w, $list, true ) ) $score[ $lang ]++;
            }
        }
        arsort( $score );
        $top = key( $score );
        $best = current( $score );
        if ( $best < 1 ) return $fallback;
        // A tie keeps the customer's selected language rather than guessing.
        $tied = 0;
        foreach ( $score as $v ) if ( $v === $best ) $tied++;
        if ( $tied > 1 && isset( $score[ $fallback ] ) && $score[ $fallback ] === $best ) return $fallback;
        return $top;
    }

    private static function t( $lang, $triplet ) {
        $i = ( 'en' === $lang ) ? 1 : ( ( 'ht' === $lang ) ? 2 : 0 );
        return isset( $triplet[ $i ] ) ? $triplet[ $i ] : $triplet[0];
    }

    /** Bonjour before noon, Bonsoir after — in the store's own timezone. */
    public static function hello_word( $lang ) {
        $hour = (int) current_time( 'G' );
        $evening = ( $hour >= 17 || $hour < 4 );
        if ( 'en' === $lang ) return ( $evening ? 'Good evening' : ( $hour < 12 ? 'Good morning' : 'Hello' ) ) . ' 👋';
        if ( 'ht' === $lang ) return ( $evening ? 'Bonswa' : 'Bonjou' ) . ' 👋';
        return ( $evening ? 'Bonsoir' : 'Bonjour' ) . ' 👋';
    }

    /**
     * Same intent twice in a row must not produce the same sentence twice, or the
     * customer sees a machine repeating itself — and DSA2_Data's duplicate guard then
     * suppresses the second reply outright, which reads as the bot going silent.
     */
    private static function variant( $conv_id, $intent, $count ) {
        if ( $count < 2 ) return 0;
        $key = 'dap_st_' . (int) $conv_id . '_' . $intent;
        $n = (int) get_transient( $key );
        set_transient( $key, $n + 1, DAY_IN_SECONDS );
        return $n % $count;
    }

    /** Name the customer volunteered ("mwen rele Jean"), kept for this conversation. */
    private static function remember_name( $conv, $name ) {
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name || DSA2_Compat::length( $name ) > 40 ) return '';
        $name = ucfirst( $name );
        if ( ! $conv || empty( $conv->id ) ) return $name;
        set_transient( 'dap_st_name_' . (int) $conv->id, $name, WEEK_IN_SECONDS );
        // customer_name is presentation only; has_contact() still gates the agent
        // handoff on e-mail/phone, so this cannot be used to skip identification.
        if ( '' === trim( (string) ( $conv->customer_name ?? '' ) ) ) {
            global $wpdb;
            $wpdb->update( $wpdb->prefix . 'dap_conversations',
                array( 'customer_name' => $name ), array( 'id' => (int) $conv->id ),
                array( '%s' ), array( '%d' ) );
        }
        return $name;
    }

    public static function known_name( $conv ) {
        if ( ! $conv || empty( $conv->id ) ) return '';
        $n = get_transient( 'dap_st_name_' . (int) $conv->id );
        if ( is_string( $n ) && '' !== $n ) return $n;
        $n = trim( (string) ( $conv->customer_name ?? '' ) );
        return ( '' !== $n && ! preg_match( '/^(visiteur|visitor|guest|client)\b/i', $n ) ) ? $n : '';
    }

    private static function fill( $text, $lang, $name ) {
        $eta = class_exists( 'DSA2_Data', false ) ? DSA2_Data::agent_eta() : '';
        $store = class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_name() : 'Delicat Store';
        return str_replace(
            array( '{store}', '{hello}', '{name}', '{eta}' ),
            array( $store, self::hello_word( $lang ), $name, $eta ),
            $text
        );
    }

    private static function chips( $kind, $lang ) {
        $tr = function( $fr, $en, $ht ) use ( $lang ) { return self::t( $lang, array( $fr, $en, $ht ) ); };
        if ( 'agent' === $kind ) {
            return array(
                array( 'label' => $tr( 'Parler à un agent', 'Talk to an agent', 'Pale ak yon ajan' ), 'action' => 'quick', 'value' => 'agent' ),
                array( 'label' => $tr( 'Suivre ma commande', 'Track my order', 'Swiv kòmand mwen' ), 'action' => 'quick', 'value' => 'last_order' ),
            );
        }
        return array(
            array( 'label' => $tr( 'Suivre ma commande', 'Track my order', 'Swiv kòmand mwen' ), 'action' => 'quick', 'value' => 'last_order' ),
            array( 'label' => $tr( 'Voir les produits', 'Browse products', 'Gade pwodwi yo' ), 'action' => 'quick', 'value' => 'products' ),
            array( 'label' => $tr( 'Aide guidée', 'Guided help', 'Èd gide' ), 'action' => 'quick', 'value' => 'help' ),
        );
    }

    /**
     * Best-matching social intent, or '' when the message is purely business.
     *
     * @return array{intent:string,lang:string,name:string,score:int}
     */
    public static function detect( $text, $lang = 'fr' ) {
        $norm = self::normalize( $text );
        $raw  = trim( (string) $text );
        $out  = array( 'intent' => '', 'lang' => $lang, 'name' => '', 'score' => 0 );
        if ( '' === $raw ) return $out;

        $out['lang'] = self::detect_language( $norm, $lang );

        // Emoji-only and single-token noise never reach the regex table.
        if ( preg_match( self::EMOJI_ONLY, $raw ) && preg_match( self::HAS_NON_ASCII, $raw )
             && DSA2_Compat::length( $raw ) <= 12 ) {
            $out['intent'] = 'emoji_only'; $out['score'] = 6;
            return $out;
        }
        // normalize() removes punctuation, so "???" / "..." arrive empty. They are a
        // customer asking for clarification, not an unparseable message.
        if ( '' === $norm ) {
            if ( DSA2_Compat::length( $raw ) <= 12 ) { $out['intent'] = 'unclear_short'; $out['score'] = 6; }
            return $out;
        }
        $len = DSA2_Compat::length( $norm );
        if ( $len > 220 ) return $out; // a paragraph is a support request, not a hello

        $table = self::table();
        $best = ''; $best_w = 0; $name = '';
        foreach ( $table as $key => $def ) {
            if ( 'emoji_only' === $key ) continue;
            if ( ! preg_match( '/' . $def['p'] . '/u', $norm, $m ) ) continue;
            if ( $def['w'] > $best_w ) {
                $best = $key; $best_w = $def['w'];
                $name = ( 'my_name' === $key && ! empty( $m[ count( $m ) - 1 ] ) ) ? $m[ count( $m ) - 1 ] : '';
            }
        }

        // Fuzzy rescue: "bonjou" typed "bonjou0", "mesii", "sakpasé la".
        if ( '' === $best ) {
            $compact = str_replace( ' ', '', $norm );
            if ( DSA2_Compat::length( $compact ) <= 14 ) {
                foreach ( self::$fuzzy as $word => $intent ) {
                    $d = levenshtein( $compact, $word );
                    $tolerance = ( strlen( $word ) <= 6 ) ? 1 : 2;
                    if ( $d <= $tolerance ) { $best = $intent; $best_w = 8; break; }
                }
            }
        }

        $out['intent'] = $best;
        $out['score']  = $best_w;
        $out['name']   = $name;
        return $out;
    }

    /**
     * The one entry point DSA2_Data calls.
     *
     * @return array|null {
     *   pure    bool   the whole message was social — return payload and stop
     *   payload array  faq_card ready to store
     *   lead    string short sentence to put in front of a business answer
     *   lang    string language actually detected
     * }
     */
    /** How many words of a normalized message say something (greetings and politeness excluded). */
    private static function content_words( $norm ) {
        $n = 0;
        foreach ( preg_split( '/\\s+/', trim( (string) $norm ) ) as $w ) {
            if ( '' !== $w && ! in_array( $w, self::FILLER, true ) ) $n++;
        }
        return $n;
    }

    public static function analyze( $text, $lang = 'fr', $conv = null ) {
        if ( ! self::enabled() ) return null;
        $hit = self::detect( $text, $lang );
        if ( '' === $hit['intent'] ) return null;

        $table = self::table();
        $def = isset( $table[ $hit['intent'] ] ) ? $table[ $hit['intent'] ] : null;
        if ( ! $def ) return null;

        $rlang = $hit['lang'];
        $norm  = self::normalize( $text );
        $has_business = (bool) preg_match( self::SUPPORT_WORDS, $norm ) || (bool) preg_match( self::SUPPORT_STEMS, $norm );
        // 7.1.0: « Est-ce que ça va arriver aujourd'hui ? », « J'attends depuis 3 jours », « Il n'y a plus
        // de diamants ? » are questions, not « ça va ? », « attends » or « à plus ».
        if ( in_array( $hit['intent'], self::SHORT_ONLY, true ) && ( $has_business || self::content_words( $norm ) > 4 ) ) return null;
        // « Yo pa reponn mwen »: in Kreyòl « yo » is « they », not « yo! ».
        if ( 'greeting' === $hit['intent'] && preg_match( '/^yo\\s+(?!bro|man|frere|zanmi|tout|everybody|tt\\b)\\S/', $norm ) ) return null;
        // A name only when it looks like one.
        if ( 'my_name' === $hit['intent'] && ( '' === $hit['name'] || in_array( $hit['name'], self::NOT_A_NAME, true ) ) ) return null;
        $conv_id = ( $conv && ! empty( $conv->id ) ) ? (int) $conv->id : 0;
        $name = $hit['name'] ? self::remember_name( $conv, $hit['name'] ) : self::known_name( $conv );

        // ask_my_name has a "I don't know it yet" second wording; pick by what we know.
        $index = self::variant( $conv_id, $hit['intent'], count( $def['say'] ) );
        if ( 'ask_my_name' === $hit['intent'] ) $index = $name ? 0 : 1;

        $say = $def['say'][ $index ];
        $body = self::fill( self::t( $rlang, $say ), $rlang, $name );
        $title = self::fill( self::t( $rlang, $def['title'] ), $rlang, $name );

        // A social opener in front of a real request: keep the greeting, let the
        // pipeline answer the request, and glue the two together afterwards.
        if ( $has_business ) {
            if ( empty( $def['lead'] ) ) return null;
            return array(
                'pure' => false,
                'lead' => self::lead_sentence( $hit['intent'], $rlang, $name ),
                'lang' => $rlang,
                'intent' => $hit['intent'],
                'payload' => null,
            );
        }

        return array(
            'pure' => true,
            'lead' => '',
            'lang' => $rlang,
            'intent' => $hit['intent'],
            'payload' => array(
                'type' => 'faq_card',
                'body' => $body,
                'meta' => array(
                    'title'      => $title,
                    'source'     => 'small_talk_v3',
                    'intent'     => $hit['intent'],
                    'lang'       => $rlang,
                    'confidence' => 0.98,
                    'chips'      => self::chips( $def['chips'], $rlang ),
                ),
            ),
        );
    }

    /** Short courtesy sentence placed before a business answer. */
    private static function lead_sentence( $intent, $lang, $name ) {
        $who = $name ? ' ' . $name : '';
        switch ( $intent ) {
            case 'greeting':
                return self::hello_word( $lang ) . $who . ' — ';
            case 'how_are_you':
                return self::t( $lang, array(
                    'Ça va très bien, merci 😊 ', 'I’m doing well, thank you 😊 ', 'Mwen byen, mèsi 😊 ' ) );
            case 'im_fine':
                return self::t( $lang, array( 'Parfait 😊 ', 'Great 😊 ', 'Trè byen 😊 ' ) );
            case 'thanks':
                return self::t( $lang, array( 'Avec plaisir 🙏 ', 'You’re welcome 🙏 ', 'Ak plezi 🙏 ' ) );
            case 'apology':
                return self::t( $lang, array( 'Aucun souci 🙂 ', 'No worries 🙂 ', 'Pa gen pwoblèm 🙂 ' ) );
            case 'my_name':
                return self::t( $lang, array(
                    'Enchanté' . $who . ' 🙂 ', 'Nice to meet you' . $who . ' 🙂 ', 'Kontan konnen w' . $who . ' 🙂 ' ) );
        }
        return '';
    }

    /**
     * Glues the courtesy lead onto whatever the business pipeline produced.
     * Card types whose body is an internal marker ("help", "misunderstood:ab12")
     * take the lead in meta.intro instead, where it is actually rendered.
     */
    public static function apply_lead( $reply, $lead ) {
        if ( ! $lead || ! is_array( $reply ) || empty( $reply['type'] ) ) return $reply;
        $type = $reply['type'];
        if ( in_array( $type, array( 'faq_card', 'text' ), true ) ) {
            $body = (string) ( $reply['body'] ?? '' );
            if ( '' !== $body && 0 !== strpos( $body, $lead ) ) $reply['body'] = $lead . $body;
            return $reply;
        }
        if ( ! isset( $reply['meta'] ) || ! is_array( $reply['meta'] ) ) $reply['meta'] = array();
        $intro = (string) ( $reply['meta']['intro'] ?? '' );
        $reply['meta']['intro'] = '' === $intro ? rtrim( $lead, '— ' ) : $lead . $intro;
        return $reply;
    }

    /** Public for the admin tester and the diagnostics screen. */
    public static function intents() {
        return array_keys( self::table() );
    }

    public static function stats() {
        $table = self::table();
        $variants = 0;
        foreach ( $table as $def ) $variants += count( $def['say'] ) * 3;
        return array( 'intents' => count( $table ), 'variants' => $variants, 'languages' => 3 );
    }

    /**
     * Intent table. Generated with data/training-conversations.csv from one source,
     * so a phrase the corpus knows is a phrase the engine knows.
     *
     *   w      matching weight — the highest weight wins when several intents match
     *   lead   may be used as a courtesy prefix in front of a business answer
     *   p      regex body, matched against the normalised message
     *   say    one triplet (fr, en, ht) per wording; rotated per conversation
     */
    private static function table() {
        static $table = null;
        if ( null !== $table ) return $table;
        $table = array(
            'greeting' => array(
                'w' => 12, 'lead' => true, 'chips' => 'default',
                'p' => '^(bonjou|bonjour|bonswa|bonsoir|bonsoua|bon\\s*swa|salut|salu|alo|allo|halo|hello|hallo|hi|hey|yo|coucou|kouku|bjr|bnj|bsr|bnswa|good\\s*(morning|afternoon|evening)|hola)\\b',
                'title' => array('Bienvenue','Welcome','Byenveni'),
                'say' => array(
                    array('{hello} Bienvenue chez {store}. Comment puis-je vous aider aujourd’hui ?','{hello} Welcome to {store}. How can I help you today?','{hello} Byenveni nan {store}. Kijan mwen ka ede w jodi a?'),
                    array('{hello} Je suis l’assistant {store}. Dites-moi ce qu’il vous faut : commande, recharge, PIN, produit…','{hello} I’m the {store} assistant. Tell me what you need: order, top-up, PIN, product…','{hello} Mwen se asistan {store}. Di m sa w bezwen: kòmand, rechaj, PIN, pwodwi…'),
                    array('{hello} Content de vous voir. Que puis-je faire pour vous ?','{hello} Good to see you. What can I do for you?','{hello} Mwen kontan wè w. Kisa m ka fè pou ou?'),
                ),
            ),
            'greeting_night' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(bon+e?\\s*nwit|bonne\\s*nuit|good\\s*night|goodnight|bon\\s*nuit|dodo\\s*byen)\\b',
                'title' => array('Bonne nuit','Good night','Bòn nwit'),
                'say' => array(
                    array('Bonne nuit 🌙 Le chat reste ouvert : laissez votre message, un agent répond dès l’ouverture.','Good night 🌙 The chat stays open — leave your message and an agent replies when we open.','Bòn nwit 🌙 Chat la rete louvri: kite mesaj ou, yon ajan ap reponn lè nou louvri.'),
                ),
            ),
            'how_are_you' => array(
                'w' => 14, 'lead' => true, 'chips' => 'default',
                'p' => '\\b((ca|sa)\\s*va(s)?|comment\\s*(ca\\s*va|allez\\s*vous|vas\\s*tu|tu\\s*vas)|vous\\s*allez\\s*bien|quoi\\s*de\\s*neuf|(kijan|kouman|koman)\\s*(ou|w| w)?\\s*(ye|ap\\s*fe)|kb\\s*ou\\s*ye|sak\\s*(pase|ap\\s*fet)|sa\\s*k\\s*ap\\s*fet|how\\s*(are\\s*you|r\\s*u|is\\s*it\\s*going|are\\s*things)|how\\s*s\\s*it\\s*going|how\\s*you\\s*doing|what\\s*s\\s*up|whats\\s*up|you\\s*good|ki\\s*jan\\s*ou\\s*ye)\\b',
                'title' => array('Ça va bien 😊','Doing well 😊','Mwen byen 😊'),
                'say' => array(
                    array('Ça va très bien, merci 😊 Et vous ? Je peux vous guider pour une commande, un produit, un paiement ou un PIN.','I’m doing well, thank you 😊 And you? I can guide you with an order, product, payment or PIN.','Mwen byen anpil, mèsi 😊 E ou menm? Mwen ka gide w pou yon kòmand, pwodwi, peman oswa PIN.'),
                    array('Tout va bien de mon côté 😊 Dites-moi comment je peux vous être utile.','All good on my side 😊 Tell me how I can be useful.','Tout bagay anfòm bò kote m 😊 Di m kijan m ka itil ou.'),
                    array('Très bien, merci de demander 😊 Qu’est-ce qui vous amène aujourd’hui ?','Very well, thanks for asking 😊 What brings you here today?','Trè byen, mèsi paske ou mande 😊 Kisa k mennen w jodi a?'),
                ),
            ),
            'im_fine' => array(
                'w' => 15, 'lead' => true, 'chips' => 'default',
                'p' => '^(je\\s*vais\\s*bien|(ca|sa)\\s*va\\s*(bien|nickel)|tout\\s*va\\s*bien|mwen\\s*byen|m\\s*byen|m\\s*ap\\s*boule|n\\s*ap\\s*boule|tout\\s*bagay\\s*(anfom|byen|ok)|i\\s*a?m\\s*(good|fine|ok|great)|doing\\s*(good|fine|well))\\b|^(bien|byen|fine|good|nickel|anfom)$',
                'title' => array('Content de l’entendre 😊','Glad to hear it 😊','Mwen kontan tande sa 😊'),
                'say' => array(
                    array('Parfait 😊 Dites-moi ce que vous souhaitez faire, ou choisissez une option ci-dessous.','Great 😊 Tell me what you’d like to do, or pick an option below.','Trè byen 😊 Di m sa ou ta renmen fè, oswa chwazi yon opsyon anba a.'),
                    array('Ça fait plaisir 😊 Je vous écoute : commande, recharge, produit ou autre chose ?','Happy to hear it 😊 I’m listening: order, top-up, product or something else?','Sa fè m plezi 😊 M ap koute w: kòmand, rechaj, pwodwi oswa lòt bagay?'),
                ),
            ),
            'im_not_fine' => array(
                'w' => 16, 'lead' => false, 'chips' => 'default',
                'p' => '\\b((ca|sa)\\s*ne?\\s*va\\s*pas|(ca|sa)\\s*va\\s*pas|pas\\s*bien|pa\\s*two\\s*byen|mwen\\s*pa\\s*byen|m\\s*pa\\s*byen|sa\\s*pa\\s*bon|not\\s*(good|great|ok)|i\\s*am\\s*(bad|sad|tired)|je\\s*suis\\s*fatigue|fatige|bouke)\\b',
                'title' => array('Je suis là pour vous','I’m here for you','Mwen la pou ou'),
                'say' => array(
                    array('Je suis désolé de l’apprendre. Expliquez-moi le problème en quelques mots et je vous guide étape par étape.','I’m sorry to hear that. Describe the problem in a few words and I’ll guide you step by step.','Mwen regrèt tande sa. Di m pwoblèm nan ak kèk mo epi m ap gide w etap pa etap.'),
                    array('Désolé d’entendre ça. On va régler ça ensemble — de quoi s’agit-il ?','Sorry to hear that. Let’s sort it out together — what is it about?','Padon pou sa. N ap regle l ansanm — de kisa li ye?'),
                ),
            ),
            'long_time' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b((ca|sa)\\s*fait\\s*longtemps|(ca|sa)\\s*fait\\s*un\\s*bail|sa\\s*fe\\s*lontan|long\\s*time|it\\s*s\\s*been\\s*a\\s*while|nou\\s*pa\\s*we\\s*lontan)\\b',
                'title' => array('Content de vous revoir','Good to see you again','Kontan wè w ankò'),
                'say' => array(
                    array('Content de vous revoir 👋 Qu’est-ce qui vous amène aujourd’hui ?','Good to see you again 👋 What brings you back today?','Kontan wè w ankò 👋 Kisa k mennen w jodi a?'),
                ),
            ),
            'thanks' => array(
                'w' => 11, 'lead' => true, 'chips' => 'default',
                'p' => '\\b(merci|mesi|msi|mrc|remercie|remesye|thanks|thank\\s*you|thank\\s*u|thx|ty|tenks|gracias)\\b',
                'title' => array('Avec plaisir 🙏','You’re welcome 🙏','Ak plezi 🙏'),
                'say' => array(
                    array('Avec plaisir 🙏 Je reste disponible : écrivez votre prochaine question quand vous voulez.','You’re welcome 🙏 I’m still here — send your next question whenever you like.','Ak plezi 🙏 Mwen toujou la: ekri pwochen kesyon w lè w vle.'),
                    array('De rien 🙏 N’hésitez pas si autre chose vous bloque.','Anytime 🙏 Don’t hesitate if something else blocks you.','Pa gen pwoblèm 🙏 Pa ezite si gen lòt bagay ki bloke w.'),
                    array('C’est un plaisir 🙏 Autre chose pour vous aujourd’hui ?','My pleasure 🙏 Anything else for you today?','Se yon plezi 🙏 Gen lòt bagay pou ou jodi a?'),
                ),
            ),
            'appreciate' => array(
                'w' => 11, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(j\\s*apprecie|apprecie|appreciate|mwen\\s*apresye|apresye)\\b',
                'title' => array('Toujours un plaisir','Always a pleasure','Toujou yon plezi'),
                'say' => array(
                    array('C’est gentil 🙏 C’est exactement pour ça que je suis là.','That’s kind 🙏 That’s exactly what I’m here for.','Sa gentiy 🙏 Se egzakteman pou sa mwen la.'),
                ),
            ),
            'apology' => array(
                'w' => 11, 'lead' => true, 'chips' => 'default',
                'p' => '\\b(pardon|padon|desole|desolee|sorry|eskize\\s*m|excus(e|ez)\\s*(moi|me)|my\\s*bad|m\\s*regret)\\b',
                'title' => array('Aucun souci','No worries','Pa gen pwoblèm'),
                'say' => array(
                    array('Aucun souci 🙂 On reprend tranquillement : dites-moi où vous en êtes.','No worries 🙂 Let’s pick it up calmly — tell me where you are.','Pa gen pwoblèm 🙂 N ap rekòmanse dousman: di m kote ou ye.'),
                ),
            ),
            'compliment' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(bon\\s*travail|bon\\s*travay|beau\\s*travail|bravo|super|genial|excellent|parfait\\s*service|good\\s*job|nice\\s*work|well\\s*done|awesome|great\\s*service|you\\s*are\\s*(great|the\\s*best|amazing)|bel\\s*sit|bon\\s*sit|sevis\\s*la\\s*bon|nou\\s*bon|ou\\s*bon|nou\\s*fe\\s*bon\\s*travay|nou\\s*top|vous\\s*etes\\s*top|top)\\b',
                'title' => array('Merci beaucoup 🙌','Thank you 🙌','Mèsi anpil 🙌'),
                'say' => array(
                    array('Merci beaucoup 🙌 Ça motive toute l’équipe {store}. Dites-moi si je peux encore vous aider.','Thank you 🙌 That means a lot to the whole {store} team. Tell me if I can help further.','Mèsi anpil 🙌 Sa ankouraje tout ekip {store} la. Di m si m ka ede w ankò.'),
                ),
            ),
            'frustration' => array(
                'w' => 17, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(nul|nulle|c\\s*est\\s*nul|sa\\s*pa\\s*bon\\s*ditou|ou\\s*pa\\s*konprann|tu\\s*comprends\\s*rien|vous\\s*comprenez\\s*rien|you\\s*don\\s*t\\s*understand|you\\s*do\\s*not\\s*understand|useless|stupid|dumb\\s*bot|this\\s*is\\s*bad|marche\\s*pas\\s*du\\s*tout|sa\\s*fatige\\s*m|j\\s*en\\s*ai\\s*marre|fed\\s*up|ridicule)\\b',
                'title' => array('Je vous passe un agent','Let me get you an agent','M ap pase w yon ajan'),
                'say' => array(
                    array('Je comprends votre frustration, et je m’excuse. Un agent humain fera mieux que moi ici — voulez-vous que je vous le passe ?','I understand the frustration, and I’m sorry. A human agent will do better than me here — shall I connect you?','Mwen konprann fristrasyon w, epi m mande w padon. Yon ajan imen ap fè pi byen pase m la a — ou vle m konekte w?'),
                ),
            ),
            'goodbye' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(au\\s*revoir|a\\s*bientot|a\\s*plus|a\\s*tantot|orevwa|n\\s*a\\s*we|na\\s*we|nawe|babay|bay\\s*bay|bye|goodbye|see\\s*you|later|take\\s*care|ciao|tchaw|adieu)\\b',
                'title' => array('À bientôt 👋','See you soon 👋','N a wè byento 👋'),
                'say' => array(
                    array('Merci d’avoir contacté {store}. Passez une excellente journée 👋','Thanks for contacting {store}. Have a great day 👋','Mèsi paske ou kontakte {store}. Pase yon bèl jounen 👋'),
                    array('À bientôt 👋 Le chat reste ici si vous avez besoin de nous.','See you soon 👋 The chat stays right here whenever you need us.','N a wè byento 👋 Chat la rete la a lè w bezwen nou.'),
                ),
            ),
            'good_day' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(bonne\\s*journee|bonne\\s*soiree|passez\\s*une\\s*bonne|have\\s*a\\s*(good|nice|great)\\s*(day|one|evening)|pase\\s*yon\\s*(bon|bel)\\s*jounen|bon\\s*jounen|bel\\s*jounen|bon\\s*swar?e)\\b',
                'title' => array('Vous aussi 🙂','You too 🙂','Ou menm tou 🙂'),
                'say' => array(
                    array('Merci, vous aussi 🙂 Revenez quand vous voulez, {store} est ouvert dans le chat.','Thank you, you too 🙂 Come back anytime — {store} is open right here in the chat.','Mèsi, ou menm tou 🙂 Tounen lè w vle, {store} louvri la a nan chat la.'),
                ),
            ),
            'blessing' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(que\\s*dieu|dieu\\s*vous\\s*benisse|bondye\\s*beni|god\\s*bless|amen)\\b',
                'title' => array('Merci 🙏','Thank you 🙏','Mèsi 🙏'),
                'say' => array(
                    array('Merci beaucoup 🙏 Que la journée vous soit belle aussi.','Thank you 🙏 May your day be a good one too.','Mèsi anpil 🙏 Se pou jounen w bèl tou.'),
                ),
            ),
            'congrats' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(bon+e?\\s*fet|bonne\\s*fete|joyeux\\s*noel|jwaye\\s*nwel|bonne\\s*annee|bon+e?\\s*ane|happy\\s*(new\\s*year|holidays|birthday)|merry\\s*christmas|congratulations|felisitasyon|felicitations)\\b',
                'title' => array('Merci 🎉','Thank you 🎉','Mèsi 🎉'),
                'say' => array(
                    array('Merci beaucoup 🎉 Toute l’équipe {store} vous souhaite la même chose !','Thank you 🎉 The whole {store} team wishes you the same!','Mèsi anpil 🎉 Tout ekip {store} la swete w menm bagay la!'),
                ),
            ),
            'yes' => array(
                'w' => 9, 'lead' => false, 'chips' => 'default',
                'p' => '^(oui|wi|ui|yes|yeah|yep|ouais|dako|dakò|d\\s*accord|ok|okay|oke|kk|bien\\s*sur|of\\s*course|sure|se\\s*sa|wi\\s*wi|oui\\s*oui)$',
                'title' => array('Parfait 👍','Perfect 👍','Trè byen 👍'),
                'say' => array(
                    array('Parfait 👍 Choisissez une option ci-dessous ou écrivez directement ce dont vous avez besoin.','Perfect 👍 Pick an option below or just type what you need.','Trè byen 👍 Chwazi yon opsyon anba a oswa ekri dirèkteman sa w bezwen.'),
                ),
            ),
            'no' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '^(non|no|nope|nan|pa\\s*sa|not\\s*really|pas\\s*vraiment|non\\s*merci|no\\s*thanks|non\\s*wi)$',
                'title' => array('Très bien','Alright','Dakò'),
                'say' => array(
                    array('Très bien. Dites-moi autre chose, ou tapez « aide » pour voir tout ce que je sais faire.','Alright. Tell me something else, or type “help” to see everything I can do.','Dakò. Di m lòt bagay, oswa ekri « èd » pou wè tout sa m ka fè.'),
                ),
            ),
            'ack' => array(
                'w' => 9, 'lead' => false, 'chips' => 'default',
                'p' => '^(compris|je\\s*comprends|c\\s*est\\s*compris|entendu|noted|got\\s*it|understood|i\\s*see|mwen\\s*konprann|m\\s*konprann|dako\\s*konprann|kompran|bon|note|ca\\s*marche|tres\\s*bien|tre\\s*byen|parfait|nice|cool|super)$',
                'title' => array('Parfait 👍','Perfect 👍','Trè byen 👍'),
                'say' => array(
                    array('Parfait 👍 Je reste ici si vous avez une autre question.','Perfect 👍 I’m right here if you have another question.','Trè byen 👍 M rete la a si w gen yon lòt kesyon.'),
                ),
            ),
            'wait' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(attends|attendez|un\\s*instant|une\\s*minute|deux\\s*secondes|tann|tann\\s*yon\\s*ti\\s*moman|tan\\s*souple|hold\\s*on|one\\s*sec|wait|give\\s*me\\s*a\\s*minute|just\\s*a\\s*moment)\\b',
                'title' => array('Je vous attends','Take your time','M ap tann ou'),
                'say' => array(
                    array('Pas de souci, prenez votre temps ⏳ Je garde la conversation ouverte.','No problem, take your time ⏳ I’ll keep the conversation open.','Pa gen pwoblèm, pran tan w ⏳ M ap kenbe konvèsasyon an louvri.'),
                ),
            ),
            'are_you_there' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '^(ou\\s*la|ou\\s*la\\s*toujou|t\\s*es\\s*la|tu\\s*es\\s*la|vous\\s*etes\\s*la|il\\s*y\\s*a\\s*quelqu\\s*un|gen\\s*moun|gen\\s*moun\\s*la|anyone\\s*there|are\\s*you\\s*there|still\\s*there)$',
                'title' => array('Je suis là ✅','I’m here ✅','Mwen la ✅'),
                'say' => array(
                    array('Je suis là ✅ Posez votre question, je réponds tout de suite.','I’m here ✅ Ask your question — I’ll answer right away.','Mwen la ✅ Poze kesyon w, m ap reponn touswit.'),
                ),
            ),
            'repeat' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(repete|repetez|redis|redites|encore\\s*une\\s*fois|say\\s*(that\\s*)?again|repeat|come\\s*again|redi\\s*sa|ankò|di\\s*l\\s*anko)\\b',
                'title' => array('Je répète','Here it is again','M ap redi l'),
                'say' => array(
                    array('Bien sûr. Dites-moi quelle partie reprendre — ou tapez le sujet et je recommence depuis le début.','Of course. Tell me which part to repeat — or type the topic and I’ll start over.','Byen si. Di m ki pati pou m repran — oswa ekri sijè a epi m rekòmanse depi nan konmansman.'),
                ),
            ),
            'dont_understand' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(je\\s*ne?\\s*comprends\\s*pas|j\\s*ai\\s*pas\\s*compris|comprends\\s*pas|c\\s*est\\s*pas\\s*clair|pas\\s*clair|mwen\\s*pa\\s*konprann|m\\s*pa\\s*konprann|pa\\s*konprann|pa\\s*kle|i\\s*don\\s*t\\s*understand|i\\s*dont\\s*understand|dont\\s*understand|what\\s*do\\s*you\\s*mean|not\\s*clear)\\b',
                'title' => array('Je simplifie','Let me simplify','M ap esplike pi senp'),
                'say' => array(
                    array('Pas de problème. Écrivez seulement le sujet : « commande », « Free Fire », « paiement », « recharge » ou « PIN ». Je poserai une question à la fois.','No problem. Just type the topic: “order”, “Free Fire”, “payment”, “top-up” or “PIN”. I’ll ask one question at a time.','Pa gen pwoblèm. Ekri sèlman sijè a: « kòmand », « Free Fire », « peman », « rechaj » oswa « PIN ». M ap poze yon sèl kesyon alafwa.'),
                ),
            ),
            'speak_creole' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(pale\\s*kreyol|an\\s*kreyol|kreyol\\s*souple|parle[rz]?\\s*creole|en\\s*creole|in\\s*creole|speak\\s*creole)\\b',
                'title' => array('Nou ap pale kreyòl','Switching to Kreyòl','Nou ap pale kreyòl'),
                'say' => array(
                    array('Pa gen pwoblèm, m ap reponn ou an kreyòl 🇭🇹 Di m sa w bezwen.','No problem, I’ll reply in Kreyòl 🇭🇹 Tell me what you need.','Pa gen pwoblèm, m ap reponn ou an kreyòl 🇭🇹 Di m sa w bezwen.'),
                ),
            ),
            'speak_french' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(parle[rz]?\\s*francais|en\\s*francais|francais\\s*svp|pale\\s*franse|an\\s*franse|in\\s*french|speak\\s*french)\\b',
                'title' => array('Je passe en français','Switching to French','M ap pale franse'),
                'say' => array(
                    array('Bien sûr, je continue en français 🇫🇷 Dites-moi ce qu’il vous faut.','Of course, I’ll continue in French 🇫🇷 Tell me what you need.','Byen si, m ap kontinye an franse 🇫🇷 Di m sa w bezwen.'),
                ),
            ),
            'speak_english' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(speak\\s*english|in\\s*english|english\\s*please|parle[rz]?\\s*anglais|en\\s*anglais|anglais\\s*svp|pale\\s*angle|an\\s*angle)\\b',
                'title' => array('Switching to English','Switching to English','M ap pale angle'),
                'say' => array(
                    array('Sure, I’ll continue in English 🇬🇧 Tell me what you need.','Sure, I’ll continue in English 🇬🇧 Tell me what you need.','Sure, I’ll continue in English 🇬🇧 Tell me what you need.'),
                ),
            ),
            'just_browsing' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(je\\s*regarde|juste\\s*un\\s*coup\\s*d\\s*oeil|je\\s*fais\\s*que\\s*regarder|just\\s*browsing|just\\s*looking|m\\s*ap\\s*gade|mwen\\s*ap\\s*gade)\\b',
                'title' => array('Prenez votre temps','Take your time','Pran tan w'),
                'say' => array(
                    array('Aucun souci, prenez votre temps 🙂 Je suis là si une question vous vient.','No problem, take your time 🙂 I’m here if a question comes up.','Pa gen pwoblèm, pran tan w 🙂 M la a si yon kesyon vin nan tèt ou.'),
                ),
            ),
            'nothing' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '^(rien|anyen|nothing|nada|rien\\s*merci|rien\\s*de\\s*special|anyen\\s*mesi|nothing\\s*thanks|pa\\s*gen\\s*anyen)$',
                'title' => array('Très bien 🙂','Alright 🙂','Dakò 🙂'),
                'say' => array(
                    array('Très bien 🙂 Je reste disponible si quelque chose vous vient à l’esprit.','Alright 🙂 I’m around if anything comes to mind.','Dakò 🙂 M rete disponib si yon bagay vin nan lide w.'),
                ),
            ),
            'still_waiting' => array(
                'w' => 17, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(j\\s*attends\\s*toujours|toujours\\s*rien|m\\s*ap\\s*tann\\s*(toujou|depi)|still\\s*waiting|no\\s*answer\\s*yet|nobody\\s*is\\s*answering|pa\\s*gen\\s*repons|personne\\s*ne\\s*repond)\\b',
                'title' => array('Je fais remonter','Escalating now','M ap fè l monte'),
                'say' => array(
                    array('Merci de votre patience, et désolé pour l’attente. Je préviens un agent tout de suite.','Thanks for your patience, and sorry for the wait. I’m alerting an agent right now.','Mèsi pou pasyans ou, epi padon pou tann nan. M ap avèti yon ajan touswit.'),
                ),
            ),
            'who_are_you' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(qui\\s*es\\s*tu|qui\\s*etes\\s*vous|c\\s*est\\s*qui|who\\s*is\\s*this|kiles\\s*ou\\s*ye|ki\\s*moun\\s*ou\\s*ye|who\\s*are\\s*you|who\\s*am\\s*i\\s*(talking|speaking)\\s*to|a\\s*qui\\s*je\\s*parle|ak\\s*ki\\s*moun\\s*m\\s*ap\\s*pale)\\b',
                'title' => array('Votre assistant {store}','Your {store} assistant','Asistan {store} ou'),
                'say' => array(
                    array('Je suis l’assistant virtuel de {store}. Je guide vos achats, vérifie vos commandes, explique les paiements MonCash/NatCash et vous transfère à un agent humain quand il le faut.','I’m {store}’s virtual assistant. I guide purchases, check orders, explain MonCash/NatCash payments and hand you to a human agent when needed.','Mwen se asistan vityèl {store}. M ap gide acha w, verifye kòmand ou, esplike peman MonCash/NatCash epi mete w ak yon ajan imen lè sa nesesè.'),
                ),
            ),
            'are_you_bot' => array(
                'w' => 15, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(es\\s*tu\\s*un\\s*robot|etes\\s*vous\\s*un\\s*robot|c\\s*est\\s*un\\s*robot|se\\s*yon\\s*robo|tu\\s*es\\s*une\\s*machine|are\\s*you\\s*(a\\s*)?(robot|bot|human|real\\s*person|machine)|is\\s*this\\s*a\\s*bot|ou\\s*se\\s*yon\\s*robo|ou\\s*se\\s*moun|ou\\s*se\\s*yon\\s*vre\\s*moun|vre\\s*moun|moun\\s*reyel)\\b',
                'title' => array('Robot, avec un humain derrière','A bot — with humans behind','Robo, ak moun dèyè l'),
                'say' => array(
                    array('Oui, je suis l’assistant automatique de {store} 🤖 Je réponds instantanément 24 h/24 sur les produits, le crédit boutique et les commandes — et je peux vous passer un agent humain à tout moment.','Yes, I’m {store}’s automated assistant 🤖 I answer instantly, 24/7, about products, store credit and orders — and I can hand you to a human agent any time.','Wi, mwen se asistan otomatik {store} 🤖 M ap reponn touswit 24/24 sou pwodwi, kredi boutik ak kòmand — epi m ka mete w ak yon ajan imen lè w vle.'),
                ),
            ),
            'what_can_you_do' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(que\\s*(peux|pouvez)\\s*tu|qu\\s*est\\s*ce\\s*que\\s*tu\\s*sais\\s*faire|tu\\s*sers\\s*a\\s*quoi|kisa\\s*ou\\s*ka\\s*fe|ki\\s*sa\\s*ou\\s*ka\\s*fe|what\\s*can\\s*you\\s*do|how\\s*can\\s*you\\s*help|what\\s*do\\s*you\\s*do)\\b',
                'title' => array('Voici ce que je sais faire','Here’s what I can do','Men sa m ka fè'),
                'say' => array(
                    array('Je peux : suivre une commande, afficher votre crédit boutique, expliquer une recharge MonCash/NatCash, retrouver un PIN, donner un prix, montrer les produits, et vous passer un agent. Tapez simplement votre besoin.','I can: track an order, show your store credit, explain a MonCash/NatCash top-up, find a PIN, give a price, show products, and connect you to an agent. Just type what you need.','M ka: swiv yon kòmand, montre kredi boutik ou, esplike yon rechaj MonCash/NatCash, jwenn yon PIN, bay yon pri, montre pwodwi, epi mete w ak yon ajan. Ekri sa w bezwen an senpleman.'),
                ),
            ),
            'bot_name' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(comment\\s*(tu\\s*t\\s*appelles|vous\\s*appelez\\s*vous)|c\\s*est\\s*quoi\\s*ton\\s*nom|ton\\s*nom|kijan\\s*ou\\s*rele|kijan\\s*yo\\s*rele\\s*w|ki\\s*non\\s*ou|what\\s*is\\s*your\\s*name|what\\s*s\\s*your\\s*name|your\\s*name)\\b',
                'title' => array('Assistant {store}','{store} Assistant','Asistan {store}'),
                'say' => array(
                    array('On m’appelle simplement l’assistant {store} 🤖 Et vous, comment vous appelez-vous ?','I’m just called the {store} assistant 🤖 And you, what’s your name?','Yo rele m senpleman asistan {store} 🤖 E ou menm, kijan ou rele?'),
                ),
            ),
            'my_name' => array(
                'w' => 15, 'lead' => true, 'chips' => 'default',
                'p' => '\\b(je\\s*m\\s*appelle|je\\s*me\\s*nomme|mon\\s*nom\\s*(c\\s*est|est)|my\\s*name\\s*is|i\\s*am\\s*called|mwen\\s*rele|m\\s*rele|non\\s*mwen\\s*se)\\s+([a-z]{2,20})',
                'title' => array('Enchanté 🙂','Nice to meet you 🙂','Kontan fè konesans ou 🙂'),
                'say' => array(
                    array('Enchanté {name} 🙂 Comment puis-je vous aider aujourd’hui ?','Nice to meet you {name} 🙂 How can I help you today?','Kontan fè konesans ou {name} 🙂 Kijan m ka ede w jodi a?'),
                ),
            ),
            'ask_my_name' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(tu\\s*(connais|sais)\\s*mon\\s*nom|comment\\s*je\\s*m\\s*appelle|ou\\s*konnen\\s*non\\s*mwen|kijan\\s*m\\s*rele|do\\s*you\\s*know\\s*my\\s*name|what\\s*is\\s*my\\s*name)\\b',
                'title' => array('Votre nom','Your name','Non ou'),
                'say' => array(
                    array('Vous m’avez dit vous appeler {name} 🙂','You told me your name is {name} 🙂','Ou te di m ou rele {name} 🙂'),
                    array('Vous ne me l’avez pas encore dit. Écrivez « je m’appelle … » et je le retiens pour cette conversation.','You haven’t told me yet. Type “my name is …” and I’ll keep it for this conversation.','Ou poko di m li. Ekri « mwen rele … » epi m ap kenbe l pou konvèsasyon sa a.'),
                ),
            ),
            'where_are_you' => array(
                'w' => 13, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(ou\\s*etes\\s*vous|vous\\s*etes\\s*ou|votre\\s*adresse|vous\\s*etes\\s*bases|kote\\s*nou\\s*ye|kote\\s*boutik\\s*la|where\\s*are\\s*you|your\\s*address|where\\s*is\\s*the\\s*store|adres\\s*nou)\\b',
                'title' => array('Où nous trouver','Where to find us','Kote pou jwenn nou'),
                'say' => array(
                    array('{store} est une boutique en ligne haïtienne : tout se passe sur le site et dans l’application. Pour une adresse physique ou une remise en main propre, un agent vous répondra précisément.','{store} is a Haitian online shop: everything happens on the site and in the app. For a physical address or hand delivery, an agent will give you the exact details.','{store} se yon boutik anliy ayisyen: tout bagay fèt sou sit la ak nan aplikasyon an. Pou yon adrès fizik oswa yon livrezon an men, yon ajan ap ba w detay egzak yo.'),
                ),
            ),
            'opening_hours' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(quelles?\\s*(sont\\s*)?(vos|les)\\s*heures|heures\\s*d\\s*ouverture|horaires|vous\\s*ouvrez\\s*(a\\s*)?quelle\\s*heure|ki\\s*le\\s*nou\\s*(louvri|femen)|nou\\s*louvri\\s*ki\\s*le|nou\\s*femen\\s*ki\\s*le|opening\\s*hours|business\\s*hours|what\\s*time\\s*do\\s*you\\s*open|when\\s*are\\s*you\\s*open)\\b',
                'title' => array('Nos horaires','Our hours','Lè nou louvri'),
                'say' => array(
                    array('Le chat est ouvert 24 h/24 : votre message est toujours enregistré. Les agents humains répondent pendant les heures d’ouverture — délai actuel : {eta}.','The chat is open 24/7 — your message is always saved. Human agents reply during business hours; current wait: {eta}.','Chat la louvri 24/24: mesaj ou toujou anrejistre. Ajan imen yo reponn pandan lè travay — delè kounye a: {eta}.'),
                ),
            ),
            'joke' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(raconte\\s*(moi\\s*)?une\\s*blague|une\\s*blague|fais\\s*moi\\s*rire|tell\\s*me\\s*a\\s*joke|say\\s*something\\s*funny|fe\\s*m\\s*ri|ba\\s*m\\s*yon\\s*blag|yon\\s*blag)\\b',
                'title' => array('Une petite blague 😄','A quick joke 😄','Yon ti blag 😄'),
                'say' => array(
                    array('Pourquoi le PIN est-il toujours calme ? Parce qu’il garde son code pour lui 😄 Bon, revenons aux choses sérieuses : que puis-je faire pour vous ?','Why is a PIN always so calm? Because it keeps its code to itself 😄 Now, back to business: what can I do for you?','Poukisa yon PIN toujou kalm? Paske li kenbe kòd li pou li menm 😄 Bon, ann tounen nan bagay serye: kisa m ka fè pou ou?'),
                ),
            ),
            'love' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(je\\s*t\\s*aime|i\\s*love\\s*you|mwen\\s*renmen\\s*w|m\\s*renmen\\s*w|love\\s*u|tu\\s*es\\s*mignon|ou\\s*bel)\\b',
                'title' => array('Merci 😊','Thank you 😊','Mèsi 😊'),
                'say' => array(
                    array('C’est très gentil 😊 Moi, ce que j’aime, c’est régler vos commandes rapidement. Dites-moi ce qu’il vous faut.','That’s very kind 😊 What I love is sorting your orders out fast. Tell me what you need.','Sa trè jantiy 😊 Sa m renmen, se regle kòmand ou yo vit. Di m sa w bezwen.'),
                ),
            ),
            'how_old' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(quel\\s*age\\s*as\\s*tu|tu\\s*as\\s*quel\\s*age|ki\\s*laj\\s*ou|how\\s*old\\s*are\\s*you)\\b',
                'title' => array('Mon âge 😄','My age 😄','Laj mwen 😄'),
                'say' => array(
                    array('Je suis aussi vieux que la dernière mise à jour de {store} 😄 Toujours neuf, donc. Que puis-je faire pour vous ?','I’m exactly as old as {store}’s last update 😄 Brand new, then. What can I do for you?','Mwen gen menm laj ak dènye mizajou {store} la 😄 Donk mwen tou nèf. Kisa m ka fè pou ou?'),
                ),
            ),
            'weather' => array(
                'w' => 12, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(quel\\s*temps\\s*fait|il\\s*fait\\s*beau|la\\s*meteo|ki\\s*jan\\s*tan\\s*an\\s*ye|tan\\s*an\\s*bel|how\\s*s\\s*the\\s*weather|the\\s*weather)\\b',
                'title' => array('La météo 🌤️','The weather 🌤️','Tan an 🌤️'),
                'say' => array(
                    array('Je ne regarde pas le ciel, seulement vos commandes 🌤️ Mais je peux vous aider tout de suite sur un achat ou un paiement.','I don’t watch the sky, only your orders 🌤️ But I can help you right away with a purchase or a payment.','Mwen pa gade syèl la, sèlman kòmand ou yo 🌤️ Men m ka ede w touswit sou yon acha oswa yon peman.'),
                ),
            ),
            'help_me' => array(
                'w' => 11, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(aide\\s*moi|aidez\\s*moi|j\\s*ai\\s*besoin\\s*d\\s*aide|besoin\\s*d\\s*aide|peux\\s*tu\\s*m\\s*aider|pouvez\\s*vous\\s*m\\s*aider|ede\\s*m|mwen\\s*bezwen\\s*ed|m\\s*bezwen\\s*ed|help\\s*me|i\\s*need\\s*help|can\\s*you\\s*help)\\b',
                'title' => array('Comment puis-je vous aider ?','How can I help?','Kijan m ka ede w?'),
                'say' => array(
                    array('Bien sûr. Dites-moi le sujet en quelques mots — par exemple « commande Free Fire non reçue », « recharge NatCash » ou « PIN invalide ».','Of course. Tell me the topic in a few words — for example “Free Fire order not received”, “NatCash top-up” or “invalid PIN”.','Byen si. Di m sijè a ak kèk mo — pa egzanp « kòmand Free Fire pa rive », « rechaj NatCash » oswa « PIN pa valab ».'),
                ),
            ),
            'emoji_only' => array(
                'w' => 6, 'lead' => false, 'chips' => 'default',
                'p' => '^[\\s]*$',
                'title' => array('Je vous écoute 🙂','I’m listening 🙂','M ap koute w 🙂'),
                'say' => array(
                    array('Je vous écoute 🙂 Écrivez votre question, même en quelques mots.','I’m listening 🙂 Type your question, even just a few words.','M ap koute w 🙂 Ekri kesyon w, menm ak kèk mo.'),
                ),
            ),
            'unclear_short' => array(
                'w' => 6, 'lead' => false, 'chips' => 'default',
                'p' => '^(hein|hen|hin|hm+|mm+|euh|eh|k|kk|huh|quoi|kisa|what)$',
                'title' => array('Dites-m’en un peu plus','Tell me a bit more','Di m yon ti jan plis'),
                'say' => array(
                    array('Dites-m’en un peu plus 🙂 Un mot suffit : commande, PIN, recharge, produit, agent…','Tell me a bit more 🙂 One word is enough: order, PIN, top-up, product, agent…','Di m yon ti jan plis 🙂 Yon mo ase: kòmand, PIN, rechaj, pwodwi, ajan…'),
                ),
            ),
            'laughing' => array(
                'w' => 11, 'lead' => false, 'chips' => 'default',
                'p' => '\\b((ha|he|hi|ja|ke){2,}|lol|mdr|ptdr|lmao|rofl)\\b',
                'title' => array('Content que ça vous plaise 😄','Glad that landed 😄','Kontan sa fè w ri 😄'),
                'say' => array(
                    array('😄 Ravi de vous voir sourire. Dites-moi ce qu’il vous faut et je m’en occupe.','😄 Good to see you smiling. Tell me what you need and I’ll handle it.','😄 Kontan wè w ap ri. Di m sa w bezwen epi m ap okipe l.'),
                ),
            ),
            'test_message' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '^(test|teste|testing|essai|eseye|alo\\s*test|test\\s*\\d*|ping)$',
                'title' => array('Le chat fonctionne ✅','The chat is working ✅','Chat la ap mache ✅'),
                'say' => array(
                    array('Message bien reçu ✅ Le chat fonctionne. Écrivez votre vraie question quand vous voulez.','Message received ✅ The chat is working. Send your real question whenever you like.','Mesaj la rive ✅ Chat la ap mache. Ekri vrè kesyon w lè w vle.'),
                ),
            ),
            'impatient' => array(
                'w' => 15, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(fe\\s*vit|vit\\s*non|pres+e|depeche|depechez\\s*vous|vite\\s*svp|hurry|hurry\\s*up|asap|be\\s*quick|prese\\s*non|m\\s*prese)\\b',
                'title' => array('Je fais au plus vite','Moving as fast as I can','M ap fè pi vit posib'),
                'say' => array(
                    array('Je comprends l’urgence. Donnez-moi le sujet en une phrase et je vous oriente tout de suite — ou je préviens un agent immédiatement.','I understand the urgency. Give me the topic in one sentence and I’ll route you right away — or I’ll alert an agent immediately.','M konprann se prese. Ban m sijè a nan yon fraz epi m ap voye w kote ki bon an touswit — oswa m avèti yon ajan imedyatman.'),
                ),
            ),
            'busy' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(m\\s*okipe|mwen\\s*okipe|je\\s*suis\\s*occupe|suis\\s*occupe|i\\s*a?m\\s*busy|pas\\s*le\\s*temps|pa\\s*gen\\s*tan)\\b',
                'title' => array('Aucun souci','No problem','Pa gen pwoblèm'),
                'say' => array(
                    array('Pas de souci 🙂 Le message reste ici, rien ne se perd. Revenez quand vous êtes libre.','No problem 🙂 The conversation stays here, nothing is lost. Come back when you’re free.','Pa gen pwoblèm 🙂 Konvèsasyon an rete la, anyen p ap pèdi. Tounen lè w lib.'),
                ),
            ),
            'call_me' => array(
                'w' => 15, 'lead' => false, 'chips' => 'agent',
                'p' => '\\b(rele\\s*m|rele\\s*mwen|appelez\\s*moi|appelle\\s*moi|call\\s*me|telefone\\s*m|tel\\s*mwen)\\b',
                'title' => array('Rappel par un agent','A call-back from an agent','Yon ajan ap rele w'),
                'say' => array(
                    array('Je ne peux pas appeler moi-même. Voulez-vous un agent ? Il vous répond ici ou sur WhatsApp — délai actuel : {eta}. Répondez « oui » ou touchez un bouton.','I can’t call you myself. Would you like an agent? They reply here or on WhatsApp — current wait: {eta}. Reply “yes” or tap a button.','Mwen menm m pa ka rele. Ou vle yon ajan? L ap reponn ou isit la oswa sou WhatsApp — delè kounye a: {eta}. Reponn « wi » oswa peze yon bouton.'),
                ),
            ),
            'new_customer' => array(
                'w' => 15, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(premye\\s*fwa\\s*m|se\\s*premye\\s*fwa|ma\\s*premiere\\s*fois|c\\s*est\\s*ma\\s*premiere|first\\s*time|premye\\s*acha\\s*mwen|nouveau\\s*client|nouvo\\s*kliyan|new\\s*customer)\\b',
                'title' => array('Bienvenue chez {store} 🎉','Welcome to {store} 🎉','Byenveni nan {store} 🎉'),
                'say' => array(
                    array('Bienvenue 🎉 Le principe est simple : vous ajoutez du crédit boutique par MonCash ou NatCash avec le TransCode reçu par SMS, puis vous achetez depuis ce solde. La livraison est instantanée dans votre compte. Je vous guide pas à pas si vous voulez.','Welcome 🎉 It works simply: add store credit by MonCash or NatCash using the TransCode from the SMS, then buy from that balance. Delivery is instant, inside your account. I can walk you through it step by step.','Byenveni 🎉 Sistèm nan senp: ou ajoute kredi boutik ak MonCash oswa NatCash avèk TransCode SMS la ba w la, epi ou achte ak balans sa a. Livrezon an fèt touswit nan kont ou. M ka gide w etap pa etap.'),
                ),
            ),
            'loyal_customer' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(kliyan\\s*regilye|m\\s*se\\s*yon\\s*kliyan|client\\s*depuis|client\\s*regulier|je\\s*suis\\s*client|regular\\s*customer|loyal\\s*customer|m\\s*achte\\s*souvan|toujou\\s*achte)\\b',
                'title' => array('Merci de votre fidélité 🙏','Thank you for your loyalty 🙏','Mèsi pou fidelite w 🙏'),
                'say' => array(
                    array('Merci de votre fidélité 🙏 Vos récompenses s’accumulent à chaque achat sur votre compte. Dites-moi ce qu’il vous faut aujourd’hui.','Thank you for your loyalty 🙏 Your rewards build up on your account with every purchase. Tell me what you need today.','Mèsi pou fidelite w 🙏 Rekonpans ou yo akimile nan kont ou chak fwa w achte. Di m sa w bezwen jodi a.'),
                ),
            ),
            'will_recommend' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(m\\s*ap\\s*pale\\s*de\\s*nou|m\\s*ap\\s*voye\\s*zanmi|je\\s*vais\\s*vous\\s*recommander|je\\s*recommande|i\\s*will\\s*recommend|recommend\\s*you|m\\s*ap\\s*rekòmande|m\\s*ap\\s*rekomande)\\b',
                'title' => array('Merci beaucoup 🙌','Thank you 🙌','Mèsi anpil 🙌'),
                'say' => array(
                    array('Merci beaucoup 🙌 C’est le plus beau compliment pour {store}. Vos amis peuvent commander directement sur le site ou dans l’application.','Thank you 🙌 That’s the best compliment {store} can get. Your friends can order straight from the site or the app.','Mèsi anpil 🙌 Se pi bèl konpliman {store} ka resevwa. Zanmi w yo ka kòmande dirèk sou sit la oswa nan aplikasyon an.'),
                ),
            ),
            'trust_you' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(m\\s*fe\\s*nou\\s*konfyans|mwen\\s*fe\\s*nou\\s*konfyans|je\\s*vous\\s*fais\\s*confiance|i\\s*trust\\s*you|nou\\s*serye|vous\\s*etes\\s*serieux)\\b',
                'title' => array('Merci de votre confiance 🙏','Thank you for trusting us 🙏','Mèsi pou konfyans ou 🙏'),
                'say' => array(
                    array('Merci de votre confiance 🙏 Chaque commande garde un numéro traçable dans votre compte, et un agent reste joignable si quelque chose cloche.','Thank you for trusting us 🙏 Every order keeps a traceable number in your account, and an agent stays reachable if anything goes wrong.','Mèsi pou konfyans ou 🙏 Chak kòmand kenbe yon nimewo ou ka swiv nan kont ou, epi yon ajan toujou disponib si yon bagay pa mache.'),
                ),
            ),
            'bad_network' => array(
                'w' => 14, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(koneksyon\\s*an\\s*pa\\s*bon|entenet\\s*la\\s*pa\\s*bon|ma\\s*connexion|mauvaise\\s*connexion|pa\\s*gen\\s*entenet|bad\\s*network|bad\\s*connection|no\\s*internet|connexion\\s*lente)\\b',
                'title' => array('Rien ne se perd','Nothing gets lost','Anyen p ap pèdi'),
                'say' => array(
                    array('Pas de souci : votre conversation est enregistrée sur nos serveurs. Même si la connexion coupe, retrouvez tout ici en revenant.','No worries: your conversation is saved on our servers. Even if the connection drops, everything is here when you come back.','Pa gen pwoblèm: konvèsasyon w anrejistre sou sèvè nou yo. Menm si koneksyon an koupe, w ap jwenn tout bagay lè w tounen.'),
                ),
            ),
            'happy' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(m\\s*kontan|mwen\\s*kontan|je\\s*suis\\s*content|je\\s*suis\\s*contente|tres\\s*content|i\\s*am\\s*happy|im\\s*happy|so\\s*happy)\\b',
                'title' => array('Ça fait plaisir 😊','That’s great to hear 😊','Sa fè plezi 😊'),
                'say' => array(
                    array('Ça fait vraiment plaisir 😊 Si vous avez besoin d’autre chose, je suis là.','That’s really good to hear 😊 If you need anything else, I’m here.','Sa fè m plezi anpil 😊 Si w bezwen lòt bagay, m la.'),
                ),
            ),
            'good_luck' => array(
                'w' => 13, 'lead' => false, 'chips' => 'default',
                'p' => '\\b(bon+e?\\s*chans|bonne\\s*chance|good\\s*luck|bon\\s*courage|kouraj)\\b',
                'title' => array('Merci 🍀','Thank you 🍀','Mèsi 🍀'),
                'say' => array(
                    array('Merci 🍀 À vous aussi. Et si quelque chose bloque, écrivez-moi.','Thank you 🍀 Same to you. And if anything blocks you, write to me.','Mèsi 🍀 Ou menm tou. Epi si yon bagay bloke w, ekri m.'),
                ),
            ),
        );
        return $table;
    }
}
