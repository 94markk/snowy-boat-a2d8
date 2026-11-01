<?php
/**
 * Step-by-step guides ("Comment faire") served inside the assistant.
 * Content lives here so it stays translatable and cache-free; the numbers,
 * delays and any custom guide come from the admin settings.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Guides {

    /**
     * 6.6.3 — guide ids that no longer exist and must never come back.
     *
     * 'exchange' (« Change et transfert — Convertir ou transférer votre argent ») described
     * a currency-exchange / money-transfer service. Google Play refused the app once for
     * financial services on a Personal developer account, and the assistant's answers are
     * shown inside the app, so the guide is gone in every language, its keywords with it.
     * An admin override line or a dap_guides filter cannot recreate it, and copies already
     * stored in a conversation are dropped when the history is read back
     * (DSA2_Data::client_messages()). « Crédit boutique » (store_credit) takes its place.
     */
    const RETIRED = array( 'exchange' );

    /** Order shown in the guide menu. */
    public static function order() {
        return array( 'recharge', 'natcash_auto', 'moncash', 'buy', 'pin', 'delivery', 'problem', 'account', 'store_credit' );
    }

    public static function is_retired( $id ) {
        return in_array( sanitize_key( (string) $id ), self::RETIRED, true );
    }

    private static function without_retired( $defs ) {
        $defs = is_array( $defs ) ? $defs : array();
        foreach ( self::RETIRED as $id ) unset( $defs[ $id ] );
        return $defs;
    }

    private static function settings() {
        return wp_parse_args( get_option( 'dap_settings', array() ), array(
            'moncash_number' => '', 'natcash_number' => '', 'min_recharge' => '', 'delivery_time' => '', 'guides_text' => '', 'guide_images' => '',
        ) );
    }

    /** Replaces {natcash} {moncash} {min} {delay} with the configured values. */
    private static function vars( $text, $lang = 'fr' ) {
        $s = self::settings();
        $fallback = array(
            'fr' => array( 'number' => 'le numéro affiché sur la page de recharge', 'min' => 'le montant minimum indiqué', 'delay' => 'quelques minutes' ),
            'en' => array( 'number' => 'the number shown on the recharge page', 'min' => 'the minimum amount shown', 'delay' => 'a few minutes' ),
            'ht' => array( 'number' => 'nimewo ki parèt sou paj rechaj la', 'min' => 'montan minimòm ki endike a', 'delay' => 'kèk minit' ),
        );
        $f = isset( $fallback[ $lang ] ) ? $fallback[ $lang ] : $fallback['fr'];
        return strtr( (string) $text, array(
            '{natcash}' => $s['natcash_number'] !== '' ? $s['natcash_number'] : $f['number'],
            '{moncash}' => $s['moncash_number'] !== '' ? $s['moncash_number'] : $f['number'],
            '{min}'     => $s['min_recharge'] !== '' ? $s['min_recharge'] : $f['min'],
            '{delay}'   => $s['delivery_time'] !== '' ? $s['delivery_time'] : $f['delay'],
        ) );
    }

    private static function label( $key, $lang ) {
        $l = array(
            'wallet'  => array( 'fr' => 'Mon crédit boutique', 'en' => 'My store credit', 'ht' => 'Kredi boutik mwen' ),
            'shop'    => array( 'fr' => 'Voir les produits', 'en' => 'Browse products', 'ht' => 'Gade pwodwi yo' ),
            'redeem'  => array( 'fr' => 'Voir mes PIN', 'en' => 'View my PINs', 'ht' => 'Gade PIN mwen' ),
            'account' => array( 'fr' => 'Mon compte', 'en' => 'My account', 'ht' => 'Kont mwen' ),
            'agent'   => array( 'fr' => 'Parler à un agent', 'en' => 'Talk to an agent', 'ht' => 'Pale ak yon ajan' ),
            'natcash' => array( 'fr' => 'Étapes Natcash Auto', 'en' => 'Natcash Auto steps', 'ht' => 'Etap Natcash Auto' ),
            'guides'  => array( 'fr' => 'Tous les guides', 'en' => 'All guides', 'ht' => 'Tout gid yo' ),
            'menu'    => array( 'fr' => 'Guides pas à pas', 'en' => 'Step-by-step guides', 'ht' => 'Gid etap pa etap' ),
            'menuSub' => array( 'fr' => 'Choisissez un sujet. Un agent reste disponible à tout moment.', 'en' => 'Pick a topic. An agent stays available at any time.', 'ht' => 'Chwazi yon sijè. Yon ajan toujou disponib.' ),
            'stillHelp' => array( 'fr' => 'Besoin de plus d’aide ?', 'en' => 'Need more help?', 'ht' => 'Ou bezwen plis èd?' ),
            'share'     => array( 'fr' => 'Partager ce guide', 'en' => 'Share this guide', 'ht' => 'Pataje gid sa a' ),
        );
        $row = isset( $l[ $key ] ) ? $l[ $key ] : array();
        return isset( $row[ $lang ] ) ? $row[ $lang ] : ( $row['fr'] ?? '' );
    }

    /**
     * Guide catalogue. Each entry: icon, keywords (comma separated, accent-free),
     * buttons, and one block per language.
     */
    public static function definitions() {
        // Rebuilt on every call before: smart_reply() alone runs match() twice and
        // payload() once, so each customer message re-parsed the whole catalogue and the
        // custom-guide overrides three or more times.
        static $cache = null;
        if ( null !== $cache ) return $cache;
        $defs = array(

            'recharge' => array(
                'icon' => '💳',
                'keywords' => 'recharge,recharger,rechaje,rechajman,depot,depo,depoze,alimenter,crediter,credit,mete lajan,top up,topup,add funds,fund wallet,balance disponible,ma balance',
                'buttons' => array( 'wallet', 'natcash', 'agent' ),
                'fr' => array(
                    'title' => 'Recharger mon solde',
                    'intro' => 'Deux méthodes sur la même page : MonCash ou Natcash Auto (TC).',
                    'steps' => array(
                        'Ouvrez Ma balance : votre solde s’affiche sous « Balance disponible ».',
                        'Touchez RECHARGER MON COMPTE pour ouvrir « Recharger votre compte ».',
                        'Choisissez l’onglet MonCash ou ⚡ Natcash Auto (TC).',
                        'Vérifiez le destinataire affiché, puis payez le montant depuis votre téléphone (minimum {min}).',
                        'Saisissez le montant exact payé dans Montant (HTG), puis le TransCode / Txn ID reçu par SMS.',
                        'Touchez 🚀 Envoyer la demande : la recharge se fait automatiquement dès réception du SMS.',
                    ),
                    'tip' => 'Le bouton « Revoir le tutoriel » en haut de la page rejoue la procédure à l’écran quand vous en avez besoin.',
                ),
                'en' => array(
                    'title' => 'Top up my balance',
                    'intro' => 'Two methods on the same page: MonCash or Natcash Auto (TC).',
                    'steps' => array(
                        'Open Ma balance — your balance is under « Balance disponible ».',
                        'Tap RECHARGER MON COMPTE to open « Recharger votre compte ».',
                        'Choose the MonCash or ⚡ Natcash Auto (TC) tab.',
                        'Check the recipient shown, then pay the amount from your phone (minimum {min}).',
                        'Type the exact amount you paid into Montant (HTG), then the TransCode / Txn ID from the SMS.',
                        'Tap 🚀 Envoyer la demande — the top-up completes automatically once the SMS arrives.',
                    ),
                    'tip' => 'The « Revoir le tutoriel » button at the top of the page replays the walkthrough on screen whenever you need it.',
                ),
                'ht' => array(
                    'title' => 'Rechaje balans mwen',
                    'intro' => 'De metòd sou menm paj la : MonCash oswa Natcash Auto (TC).',
                    'steps' => array(
                        'Louvri Ma balance : balans ou parèt anba « Balance disponible ».',
                        'Peze RECHARGER MON COMPTE pou louvri « Recharger votre compte ».',
                        'Chwazi onglè MonCash oswa ⚡ Natcash Auto (TC).',
                        'Tcheke moun k ap resevwa a, epi peye montan an ak telefòn ou (minimòm {min}).',
                        'Mete menm montan ou peye a nan Montant (HTG), apre sa TransCode / Txn ID ou resevwa nan SMS la.',
                        'Peze 🚀 Envoyer la demande : rechaj la fèt otomatikman lè SMS la rive.',
                    ),
                    'tip' => 'Bouton « Revoir le tutoriel » anwo paj la remontre w tout etap yo sou ekran an lè w bezwen.',
                ),
            ),

            'natcash_auto' => array(
                'icon' => '⚡',
                'keywords' => 'natcash,nat cash,natcash auto,transcode,trans code,txn id,txn,kod tranzaksyon,tc natcash',
                'buttons' => array( 'wallet', 'agent' ),
                'fr' => array(
                    'title' => 'Natcash Auto (TransCode)',
                    'intro' => 'Validation automatique dès réception du SMS Natcash — en quelques secondes.',
                    'steps' => array(
                        'Ouvrez Ma balance, puis touchez RECHARGER MON COMPTE.',
                        'Choisissez l’onglet ⚡ Natcash Auto (TC).',
                        'Vérifiez le destinataire : Numéro {natcash}. Touchez l’œil 👁 à côté du nom pour l’afficher en entier avant de payer.',
                        'Depuis votre Natcash, payez le montant à ce numéro et retenez le montant exact payé.',
                        'Revenez sur la page : saisissez ce même montant dans Montant (HTG), puis collez le TransCode / Txn ID du SMS, sans espace.',
                        'Touchez 🚀 Envoyer la demande. Le solde monte tout seul dès que le SMS Natcash arrive.',
                    ),
                    'tip' => 'La validation automatique compare deux choses : le TransCode ET le montant exact. Un montant saisi différent de celui payé bloque la validation et oblige un agent à intervenir. Un TransCode ne sert qu’une seule fois.',
                ),
                'en' => array(
                    'title' => 'Natcash Auto (TransCode)',
                    'intro' => 'Validated automatically as soon as the Natcash SMS lands — a few seconds.',
                    'steps' => array(
                        'Open Ma balance, then tap RECHARGER MON COMPTE.',
                        'Choose the ⚡ Natcash Auto (TC) tab.',
                        'Check the recipient: Numéro {natcash}. Tap the eye 👁 next to the name to see it in full before paying.',
                        'From your Natcash, pay the amount to that number and remember the exact amount paid.',
                        'Back on the page: type that same amount into Montant (HTG), then paste the TransCode / Txn ID from the SMS, with no spaces.',
                        'Tap 🚀 Envoyer la demande. The balance rises on its own once the Natcash SMS arrives.',
                    ),
                    'tip' => 'Automatic validation matches two things: the TransCode AND the exact amount. An amount that differs from what you paid blocks it and needs an agent. A TransCode works only once.',
                ),
                'ht' => array(
                    'title' => 'Natcash Auto (TransCode)',
                    'intro' => 'Validasyon otomatik kou SMS Natcash la rive — nan kèk segond.',
                    'steps' => array(
                        'Louvri Ma balance, epi peze RECHARGER MON COMPTE.',
                        'Chwazi onglè ⚡ Natcash Auto (TC).',
                        'Tcheke moun k ap resevwa a : Numéro {natcash}. Peze je a 👁 bò kote non an pou w wè l nèt anvan ou peye.',
                        'Ak Natcash ou, peye montan an sou nimewo sa a epi sonje montan egzak ou peye a.',
                        'Tounen sou paj la : mete menm montan an nan Montant (HTG), apre sa kole TransCode / Txn ID SMS la, san espas.',
                        'Peze 🚀 Envoyer la demande. Balans lan monte pou kont li kou SMS Natcash la rive.',
                    ),
                    'tip' => 'Validasyon otomatik la konpare de bagay : TransCode a AK montan egzak la. Si montan ou mete a pa menm ak sa ou peye a, validasyon an bloke epi se yon ajan ki dwe regle l. Yon TransCode mache yon sèl fwa.',
                ),
            ),

            'moncash' => array(
                'icon' => '📲',
                'keywords' => 'moncash,mon cash,digicel,transfer moncash,detay transfe moncash',
                'buttons' => array( 'wallet', 'agent' ),
                'fr' => array(
                    'title' => 'Recharger avec MonCash',
                    'intro' => 'Même procédure que Natcash Auto, sur l’onglet MonCash.',
                    'steps' => array(
                        'Ouvrez Ma balance, puis touchez RECHARGER MON COMPTE.',
                        'Restez sur l’onglet MonCash (sélectionné par défaut).',
                        'Vérifiez le destinataire : Numéro {moncash}. Touchez l’œil 👁 pour afficher le nom en entier.',
                        'Depuis MonCash, payez le montant exact à ce numéro et gardez le SMS de confirmation.',
                        'Saisissez ce même montant dans Montant (HTG), puis le TransCode / Txn ID du SMS.',
                        'Touchez 🚀 Envoyer la demande : la validation se fait dès réception du SMS.',
                    ),
                    'tip' => 'Le montant saisi doit correspondre exactement au montant payé, sinon la vérification échoue. Payez depuis votre propre numéro.',
                ),
                'en' => array(
                    'title' => 'Top up with MonCash',
                    'intro' => 'Same procedure as Natcash Auto, on the MonCash tab.',
                    'steps' => array(
                        'Open Ma balance, then tap RECHARGER MON COMPTE.',
                        'Stay on the MonCash tab (selected by default).',
                        'Check the recipient: Numéro {moncash}. Tap the eye 👁 to see the full name.',
                        'From MonCash, pay the exact amount to that number and keep the confirmation SMS.',
                        'Type that same amount into Montant (HTG), then the TransCode / Txn ID from the SMS.',
                        'Tap 🚀 Envoyer la demande — validation happens as soon as the SMS arrives.',
                    ),
                    'tip' => 'The amount you type must match the amount you paid exactly, otherwise verification fails. Pay from your own number.',
                ),
                'ht' => array(
                    'title' => 'Rechaje ak MonCash',
                    'intro' => 'Menm pwosedi ak Natcash Auto, sou onglè MonCash la.',
                    'steps' => array(
                        'Louvri Ma balance, epi peze RECHARGER MON COMPTE.',
                        'Rete sou onglè MonCash la (li chwazi deja).',
                        'Tcheke moun k ap resevwa a : Numéro {moncash}. Peze je a 👁 pou w wè non an nèt.',
                        'Ak MonCash, peye montan egzak la sou nimewo sa a epi kenbe SMS konfimasyon an.',
                        'Mete menm montan an nan Montant (HTG), apre sa TransCode / Txn ID SMS la.',
                        'Peze 🚀 Envoyer la demande : validasyon an fèt kou SMS la rive.',
                    ),
                    'tip' => 'Montan ou mete a dwe egzakteman menm ak sa ou peye a, sinon verifikasyon an ap echwe. Peye ak pwòp nimewo pa w.',
                ),
            ),

            'buy' => array(
                'icon' => '🛒',
                'keywords' => 'commander,komande,passer commande,comment acheter,kijan pou achte,how to buy,how to order,panier,checkout,payer ma commande',
                'buttons' => array( 'shop', 'wallet', 'agent' ),
                'fr' => array(
                    'title' => 'Passer une commande',
                    'intro' => 'De la sélection du produit à la livraison.',
                    'steps' => array(
                        'Ouvrez la boutique et choisissez votre produit (jeu, carte cadeau, recharge).',
                        'Sélectionnez le montant ou le pack souhaité.',
                        'Ajoutez au panier, puis allez au paiement.',
                        'Renseignez les informations demandées : ID de joueur, numéro ou e-mail selon le produit.',
                        'Payez avec votre crédit boutique, MonCash, Natcash ou carte.',
                        'La livraison arrive dans votre compte et par e-mail sous {delay}.',
                    ),
                    'tip' => 'Vérifiez votre ID de joueur avant de payer : une commande livrée sur un mauvais ID ne peut pas être annulée.',
                ),
                'en' => array(
                    'title' => 'Place an order',
                    'intro' => 'From picking the product to delivery.',
                    'steps' => array(
                        'Open the shop and choose your product (game, gift card, recharge).',
                        'Select the amount or pack you want.',
                        'Add it to the cart, then go to checkout.',
                        'Fill in what the product needs: player ID, phone number or e-mail.',
                        'Pay with your store credit, MonCash, Natcash or card.',
                        'Delivery lands in your account and by e-mail within {delay}.',
                    ),
                    'tip' => 'Double-check your player ID before paying: an order delivered to the wrong ID cannot be reversed.',
                ),
                'ht' => array(
                    'title' => 'Fè yon kòmand',
                    'intro' => 'Depi ou chwazi pwodwi a jiska livrezon.',
                    'steps' => array(
                        'Louvri boutik la epi chwazi pwodwi ou a (jwèt, kat kado, rechaj).',
                        'Chwazi montan oswa pak ou vle a.',
                        'Mete l nan panye a, apre sa ale nan peman.',
                        'Ranpli sa pwodwi a mande : ID jwè, nimewo oswa imel.',
                        'Peye ak kredi boutik ou, MonCash, Natcash oswa kat.',
                        'Livrezon an rive nan kont ou ak nan imel nan {delay}.',
                    ),
                    'tip' => 'Tcheke ID jwè ou anvan ou peye : yon kòmand ki livre sou move ID pa ka retounen.',
                ),
            ),

            'pin' => array(
                'icon' => '💎',
                'keywords' => 'pin,redeem,rachat,utiliser pin,itilize pin,free fire pin,kod free fire',
                'buttons' => array( 'redeem', 'agent' ),
                'fr' => array(
                    'title' => 'Utiliser un PIN Free Fire',
                    'intro' => 'Récupérez et utilisez le code livré avec votre commande.',
                    'steps' => array(
                        'Ouvrez la page Mes PIN depuis votre compte.',
                        'Copiez le code correspondant à votre commande.',
                        'Ouvrez le centre de recharge officiel du jeu.',
                        'Choisissez la méthode carte de recharge / PIN et collez le code.',
                        'Confirmez : les diamants arrivent sur votre ID de joueur.',
                    ),
                    'tip' => 'Ne partagez jamais votre PIN. Un code déjà utilisé ne peut pas être remplacé.',
                ),
                'en' => array(
                    'title' => 'Use a Free Fire PIN',
                    'intro' => 'Find and redeem the code delivered with your order.',
                    'steps' => array(
                        'Open the My PINs page from your account.',
                        'Copy the code matching your order.',
                        'Open the official game recharge centre.',
                        'Choose the recharge card / PIN method and paste the code.',
                        'Confirm — the diamonds land on your player ID.',
                    ),
                    'tip' => 'Never share your PIN. A code that has already been used cannot be replaced.',
                ),
                'ht' => array(
                    'title' => 'Itilize yon PIN Free Fire',
                    'intro' => 'Jwenn epi itilize kòd ki vini ak kòmand ou an.',
                    'steps' => array(
                        'Louvri paj PIN mwen yo nan kont ou.',
                        'Kopye kòd ki koresponn ak kòmand ou an.',
                        'Louvri sant rechaj ofisyèl jwèt la.',
                        'Chwazi metòd kat rechaj / PIN epi kole kòd la.',
                        'Konfime — dyaman yo rive sou ID jwè ou.',
                    ),
                    'tip' => 'Pa janm pataje PIN ou. Yon kòd ki deja itilize pa ka ranplase.',
                ),
            ),

            'delivery' => array(
                'icon' => '⏱️',
                'keywords' => 'livraison,livrezon,delai,delay,combien de temps,konbyen tan,en attente,pending,processing',
                'buttons' => array( 'account', 'agent' ),
                'fr' => array(
                    'title' => 'Délai de livraison',
                    'intro' => 'Quand et où arrive votre commande.',
                    'steps' => array(
                        'Le paiement est vérifié dès sa réception.',
                        'Les produits numériques sont livrés sous {delay} après validation.',
                        'La livraison apparaît dans Mon compte, section Commandes.',
                        'Un e-mail de confirmation part vers l’adresse de votre compte.',
                        'Si la commande reste En attente au-delà du délai, ouvrez le chat avec un agent.',
                    ),
                    'tip' => 'Vérifiez vos spams : les e-mails de livraison y arrivent parfois.',
                ),
                'en' => array(
                    'title' => 'Delivery time',
                    'intro' => 'When and where your order arrives.',
                    'steps' => array(
                        'Payment is verified as soon as it arrives.',
                        'Digital products are delivered within {delay} after validation.',
                        'The delivery shows up in My account, Orders section.',
                        'A confirmation e-mail goes to your account address.',
                        'If the order stays Pending past that window, open the chat with an agent.',
                    ),
                    'tip' => 'Check your spam folder — delivery e-mails sometimes land there.',
                ),
                'ht' => array(
                    'title' => 'Delè livrezon',
                    'intro' => 'Kilè ak ki kote kòmand ou an rive.',
                    'steps' => array(
                        'Peman an verifye kou li rive.',
                        'Pwodwi dijital yo livre nan {delay} apre validasyon.',
                        'Livrezon an parèt nan Kont mwen, seksyon Kòmand.',
                        'Yon imel konfimasyon ale nan adrès kont ou.',
                        'Si kòmand lan rete An atant apre delè a, louvri chat la ak yon ajan.',
                    ),
                    'tip' => 'Tcheke dosye spam ou — imel livrezon yo konn rive la.',
                ),
            ),

            'problem' => array(
                'icon' => '🛟',
                'keywords' => 'probleme,pwoblem,erreur,error,paye mais,mwen peye,pa kredite,pas credite,pa antre,pa rive,pa paret,pa resevwa,pas recu,not received,toujou pa,solde manquant,pa credite,argent perdu,lajan pedi,reclamation,plainte,bloke,echec,echoue,failed,refuse,decline,rejete,montant different,move montan',
                'buttons' => array( 'agent', 'wallet' ),
                'fr' => array(
                    'title' => 'Paiement effectué, solde non crédité',
                    'intro' => 'Presque toujours le montant. Vérifiez ces trois points avant d’écrire.',
                    'steps' => array(
                        'Le montant : celui saisi dans Montant doit être exactement celui payé. C’est la cause n°1 d’une recharge qui ne part pas.',
                        'Le TransCode : complet, sans espace, copié du SMS, et jamais utilisé auparavant.',
                        'Le numéro de paiement : la validation automatique attend le SMS de votre propre numéro.',
                        'Regardez Ma balance : la validation prend {delay} après le SMS.',
                        'Toujours rien ? Écrivez ici la date, l’heure, le montant et le TransCode — un agent vérifie et corrige.',
                    ),
                    'tip' => 'N’effectuez pas un second paiement avant la réponse de l’agent : le premier n’est pas perdu.',
                ),
                'en' => array(
                    'title' => 'Payment made, balance missing',
                    'intro' => 'Almost always the amount. Check these three before writing.',
                    'steps' => array(
                        'The amount: what you typed into Montant must be exactly what you paid. This is the number one cause of a top-up that never lands.',
                        'The TransCode: complete, no spaces, copied from the SMS, and never used before.',
                        'The number you pay from: automatic validation expects the SMS from your own number.',
                        'Check Ma balance — validation takes {delay} after the SMS.',
                        'Still nothing? Write the date, time, amount and TransCode here and an agent will check and fix it.',
                    ),
                    'tip' => 'Do not make a second payment before an agent replies — the first one is not lost.',
                ),
                'ht' => array(
                    'title' => 'Peman fèt, balans lan pa antre',
                    'intro' => 'Se prèske toujou montan an. Tcheke twa bagay sa yo anvan ou ekri.',
                    'steps' => array(
                        'Montan an : sa ou mete nan Montant dwe egzakteman sa ou peye a. Se premye kòz yon rechaj ki pa pase.',
                        'TransCode a : konplè, san espas, kopye nan SMS la, epi li pa t janm sèvi anvan.',
                        'Nimewo ki peye a : validasyon otomatik la ap tann SMS la soti nan pwòp nimewo pa w.',
                        'Gade Ma balance : validasyon an pran {delay} apre SMS la.',
                        'Toujou anyen? Ekri dat, lè, montan ak TransCode la isit — yon ajan ap tcheke epi korije.',
                    ),
                    'tip' => 'Pa fè yon dezyèm peman anvan yon ajan reponn ou : premye a pa pèdi.',
                ),
            ),

            'account' => array(
                'icon' => '👤',
                'keywords' => 'compte,kont,account,inscription,enskripsyon,creer un compte,mot de passe,modpas,password,connexion,konekte,login',
                'buttons' => array( 'account', 'agent' ),
                'fr' => array(
                    'title' => 'Créer ou récupérer mon compte',
                    'intro' => 'Un compte garde vos commandes, votre solde et vos PIN.',
                    'steps' => array(
                        'Ouvrez la page Mon compte.',
                        'Choisissez Créer un compte et entrez votre e-mail et votre numéro.',
                        'Confirmez votre mot de passe et validez.',
                        'Mot de passe oublié : cliquez Mot de passe oublié sur la même page.',
                        'Le lien de réinitialisation arrive par e-mail en quelques minutes.',
                    ),
                    'tip' => 'Utilisez le numéro avec lequel vous payez : les recharges automatiques y sont liées.',
                ),
                'en' => array(
                    'title' => 'Create or recover my account',
                    'intro' => 'An account keeps your orders, balance and PINs.',
                    'steps' => array(
                        'Open the My account page.',
                        'Choose Create an account and enter your e-mail and phone number.',
                        'Confirm your password and submit.',
                        'Forgot your password: click Lost password on the same page.',
                        'The reset link arrives by e-mail within a few minutes.',
                    ),
                    'tip' => 'Use the number you pay from — automatic top-ups are tied to it.',
                ),
                'ht' => array(
                    'title' => 'Kreye oswa rekipere kont mwen',
                    'intro' => 'Yon kont kenbe kòmand ou, balans ou ak PIN ou yo.',
                    'steps' => array(
                        'Louvri paj Kont mwen an.',
                        'Chwazi Kreye yon kont epi mete imel ou ak nimewo w.',
                        'Konfime modpas ou epi valide.',
                        'Ou bliye modpas la : klike Modpas bliye sou menm paj la.',
                        'Lyen pou remete l la rive nan imel nan kèk minit.',
                    ),
                    'tip' => 'Sèvi ak nimewo ou konn peye avè l la — rechaj otomatik yo lye ak li.',
                ),
            ),

            // 6.6.3 — replaces the retired 'exchange' guide (see RETIRED). Closed-loop store
            // credit in HTG: spent in the shop only, never withdrawn or converted. Neutral
            // keywords only. credit / recharger / moncash / natcash are listed as well, but the
            // recharge, MonCash and Natcash guides come first and match() keeps the first guide
            // on a tie, so those words keep opening their own guide; the longer phrases
            // (crédit boutique, withdraw, retire balans…) are what open this one.
            // yield_to: an explainer, not a fix. « Mon crédit boutique n'est pas crédité » or
            // « kredi boutik mwen pa antre » also hits a (longer) phrase here, but it is a
            // complaint: whenever the problem guide matched too, match() returns it instead.
            'store_credit' => array(
                'icon' => '🛍️',
                'keywords' => 'credit,recharger,moncash,natcash,credit boutique,kredi boutik,store credit,shop credit,credit delicat,kredi delicat,cash out,withdraw,retirer mon solde,retire balans',
                'yield_to' => array( 'problem' ),
                'buttons' => array( 'wallet', 'agent' ),
                'fr' => array(
                    'title' => 'Crédit boutique',
                    'intro' => 'Ajoutez du crédit par MonCash ou Natcash et utilisez-le pour vos commandes.',
                    'steps' => array(
                        'Le crédit boutique est un solde en HTG lié à votre compte. Il sert uniquement à payer vos commandes dans la boutique.',
                        'Pour en ajouter, ouvrez Ma balance puis RECHARGER MON COMPTE, et choisissez MonCash ou ⚡ Natcash Auto (TC).',
                        'Payez le montant (minimum {min}), puis saisissez le montant exact et le TransCode reçu par SMS : le crédit est ajouté automatiquement.',
                        'Au paiement de votre commande, choisissez le crédit boutique : le prix est déduit de votre solde.',
                        'Le solde restant reste disponible pour vos prochaines commandes.',
                    ),
                    'tip' => 'Le crédit boutique ne sert qu’aux achats dans la boutique : il ne peut être ni retiré ni converti en espèces.',
                ),
                'en' => array(
                    'title' => 'Store credit',
                    'intro' => 'Add credit with MonCash or Natcash and use it for your orders.',
                    'steps' => array(
                        'Store credit is an HTG balance tied to your account. It can only be used to pay for orders in the shop.',
                        'To add some, open Ma balance then RECHARGER MON COMPTE, and choose MonCash or ⚡ Natcash Auto (TC).',
                        'Pay the amount (minimum {min}), then type the exact amount and the TransCode from the SMS: the credit is added automatically.',
                        'At checkout, choose store credit: the price is taken from your balance.',
                        'Whatever is left stays available for your next orders.',
                    ),
                    'tip' => 'Store credit is for purchases in the shop only: it cannot be withdrawn or converted to cash.',
                ),
                'ht' => array(
                    'title' => 'Kredi boutik',
                    'intro' => 'Ajoute kredi ak MonCash oswa Natcash epi sèvi avè l pou kòmand ou yo.',
                    'steps' => array(
                        'Kredi boutik la se yon balans an HTG ki mache ak kont ou. Li sèvi sèlman pou peye kòmand nan boutik la.',
                        'Pou w ajoute, louvri Ma balance epi RECHARGER MON COMPTE, apre sa chwazi MonCash oswa ⚡ Natcash Auto (TC).',
                        'Peye montan an (minimòm {min}), epi mete montan egzak la ak TransCode ou resevwa nan SMS la : kredi a ajoute otomatikman.',
                        'Lè w ap peye kòmand lan, chwazi kredi boutik : yo retire pri a nan balans ou.',
                        'Sa ki rete a toujou disponib pou pwochen kòmand ou yo.',
                    ),
                    'tip' => 'Kredi boutik la sèvi sèlman pou achte nan boutik la : ou pa ka retire l ni konvèti l an kach.',
                ),
            ),
        );

        /** Allow custom guides / overrides from the settings screen, then from code. */
        // 6.6.3: a retired guide stays retired whatever the overrides or the filter return.
        $defs = self::without_retired( self::apply_overrides( $defs ) );
        $cache = self::without_retired( apply_filters( 'dap_guides', $defs ) );
        return $cache;
    }

    /**
     * Admin overrides, one per line:
     * id|lang|Title|Step 1;Step 2 @@ https://image.jpg;Step 3|keyword1,keyword2
     * An unknown id creates a brand new guide.
     */
    private static function apply_overrides( $defs ) {
        $s = self::settings();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['guides_text'] ) as $line ) {
            $line = trim( $line );
            if ( $line === '' || substr_count( $line, '|' ) < 3 ) continue;
            $parts = array_map( 'trim', explode( '|', $line ) );
            $id = sanitize_key( $parts[0] );
            $lang = in_array( $parts[1], array( 'fr', 'en', 'ht' ), true ) ? $parts[1] : 'fr';
            $title = $parts[2];
            $steps = array_values( array_filter( array_map( 'trim', explode( ';', $parts[3] ) ) ) );
            if ( $id === '' || $title === '' || ! $steps ) continue;
            if ( ! isset( $defs[ $id ] ) ) {
                $defs[ $id ] = array( 'icon' => '📘', 'keywords' => '', 'buttons' => array( 'agent' ) );
            }
            $defs[ $id ][ $lang ] = array( 'title' => $title, 'intro' => '', 'steps' => $steps, 'tip' => '' );
            if ( ! empty( $parts[4] ) ) {
                // The same guide declared in fr, en and ht appended its keywords three times.
                $merged = array_filter( array_map( 'trim', explode( ',', $defs[ $id ]['keywords'] . ',' . $parts[4] ) ) );
                $defs[ $id ]['keywords'] = implode( ',', array_unique( $merged ) );
            }
        }
        return $defs;
    }

    /**
     * Screenshots attached to built-in guide steps, one per line:
     *   natcash_auto|3|https://delicastoreha.com/wp-content/uploads/tuto-tc.png
     * Step numbers are the ones the customer sees (1, 2, 3…). Custom guides can still
     * inline an image with "@@ url"; this exists so the shipped guides can carry the
     * store's own screenshots without retyping the whole guide.
     */
    private static function step_images( $id ) {
        static $map = null;
        if ( null === $map ) {
            $map = array();
            $s = self::settings();
            foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['guide_images'] ) as $line ) {
                $parts = array_map( 'trim', explode( '|', trim( $line ) ) );
                if ( count( $parts ) < 3 ) continue;
                $gid = sanitize_key( $parts[0] );
                $step = (int) $parts[1];
                $url = $parts[2];
                if ( ! $gid || $step < 1 || ! wp_http_validate_url( $url ) ) continue;
                $map[ $gid ][ $step - 1 ] = esc_url_raw( $url );
            }
        }
        return isset( $map[ $id ] ) ? $map[ $id ] : array();
    }

    /** Turns button keys into the payload the widget renders. */
    private static function buttons( $keys, $lang ) {
        $out = array();
        foreach ( (array) $keys as $key ) {
            switch ( $key ) {
                case 'wallet':  $out[] = array( 'label' => self::label( 'wallet', $lang ), 'action' => 'url', 'value' => 'wallet' ); break;
                case 'shop':    $out[] = array( 'label' => self::label( 'shop', $lang ), 'action' => 'url', 'value' => 'shop' ); break;
                case 'redeem':  $out[] = array( 'label' => self::label( 'redeem', $lang ), 'action' => 'url', 'value' => 'redeem' ); break;
                case 'account': $out[] = array( 'label' => self::label( 'account', $lang ), 'action' => 'url', 'value' => 'account' ); break;
                case 'natcash': $out[] = array( 'label' => self::label( 'natcash', $lang ), 'action' => 'guide', 'value' => 'natcash_auto' ); break;
                case 'agent':   $out[] = array( 'label' => self::label( 'agent', $lang ), 'action' => 'agent', 'value' => '' ); break;
            }
        }
        return $out;
    }

    public static function exists( $id ) {
        $defs = self::definitions();
        return isset( $defs[ $id ] );
    }

    /** guide_card payload for one guide. */
    public static function payload( $id, $lang = 'fr' ) {
        $defs = self::definitions();
        $id = sanitize_key( $id );
        if ( ! isset( $defs[ $id ] ) ) return null;
        $g = $defs[ $id ];
        $c = isset( $g[ $lang ] ) ? $g[ $lang ] : ( isset( $g['fr'] ) ? $g['fr'] : null );
        if ( ! $c || empty( $c['steps'] ) ) return null;

        $steps = array();
        $images = array();
        $attached = self::step_images( $id );
        $index = 0;
        foreach ( (array) $c['steps'] as $step ) {
            // "Step text @@ https://site.com/screenshot.png" attaches an illustration.
            $img = '';
            if ( false !== strpos( $step, '@@' ) ) {
                list( $step, $raw ) = array_pad( explode( '@@', $step, 2 ), 2, '' );
                $raw = trim( $raw );
                if ( $raw && wp_http_validate_url( $raw ) ) $img = esc_url_raw( $raw );
            }
            if ( ! $img && isset( $attached[ $index ] ) ) $img = $attached[ $index ];
            $steps[]  = self::vars( wp_strip_all_tags( trim( $step ) ), $lang );
            $images[] = $img;
            $index++;
        }
        $title = self::vars( wp_strip_all_tags( $c['title'] ), $lang );
        $tip   = ! empty( $c['tip'] ) ? self::vars( wp_strip_all_tags( $c['tip'] ), $lang ) : '';
        $icon  = isset( $g['icon'] ) ? $g['icon'] : '📘';

        // Shareable version: plain numbered text for WhatsApp, plus a link that reopens
        // this exact guide for whoever receives it.
        $lines = array( $icon . ' ' . $title );
        foreach ( $steps as $i => $step ) $lines[] = ( $i + 1 ) . '. ' . $step;
        if ( $tip ) $lines[] = '💡 ' . $tip;
        $lines[] = '— ' . DSA2_Data::store_name();
        $share = array(
            'label' => self::label( 'share', $lang ),
            'title' => $title,
            'text'  => implode( "\n", $lines ),
            'url'   => add_query_arg( array( 'dap_guide' => $id, 'lang' => $lang ), home_url( '/' ) ),
        );

        return array(
            'type' => 'guide_card',
            'body' => 'guide:' . $id,
            'meta' => array(
                'guide'   => $id,
                'icon'    => $icon,
                'title'   => $title,
                'intro'   => isset( $c['intro'] ) ? self::vars( wp_strip_all_tags( $c['intro'] ), $lang ) : '',
                'steps'   => $steps,
                'images'  => array_filter( $images ) ? $images : array(),
                'tip'     => $tip,
                'help'    => self::label( 'stillHelp', $lang ),
                'share'   => $share,
                'buttons' => self::buttons( isset( $g['buttons'] ) ? $g['buttons'] : array( 'agent' ), $lang ),
                'chips'   => array( array( 'label' => self::label( 'guides', $lang ), 'action' => 'quick', 'value' => 'guides' ) ),
            ),
        );
    }

    /** guide_menu payload: the list of every available guide. */
    public static function menu_payload( $lang = 'fr' ) {
        $defs = self::definitions();
        $ids = self::order();
        foreach ( array_keys( $defs ) as $id ) {
            if ( ! in_array( $id, $ids, true ) ) $ids[] = $id;
        }
        $items = array();
        foreach ( $ids as $id ) {
            if ( ! isset( $defs[ $id ] ) ) continue;
            $c = isset( $defs[ $id ][ $lang ] ) ? $defs[ $id ][ $lang ] : ( isset( $defs[ $id ]['fr'] ) ? $defs[ $id ]['fr'] : null );
            if ( ! $c || empty( $c['title'] ) ) continue;
            $items[] = array(
                'id'    => $id,
                'icon'  => isset( $defs[ $id ]['icon'] ) ? $defs[ $id ]['icon'] : '📘',
                'title' => self::vars( wp_strip_all_tags( $c['title'] ), $lang ),
            );
        }
        if ( ! $items ) return null;
        return array(
            'type' => 'guide_menu',
            'body' => 'guides',
            'meta' => array(
                'title'   => self::label( 'menu', $lang ),
                'intro'   => self::label( 'menuSub', $lang ),
                'items'   => $items,
                'buttons' => self::buttons( array( 'agent' ), $lang ),
            ),
        );
    }

    /**
     * Best matching guide id for an accent-free lowercase message, or ''.
     * $min is the length the matched keyword must reach — raise it to only
     * accept a specific term ("rechaje", "transcode") over a vague one.
     *
     * The longest keyword wins, and the first guide in the list on a tie. 6.6.3: a guide
     * with 'yield_to' gives way to any of those guides that also reached $min (the
     * « Crédit boutique » explainer to the « solde non crédité » troubleshooting guide).
     */
    public static function match( $normalized, $min = 3 ) {
        $defs = self::definitions();
        $best = ''; $score = 0; $scores = array();
        foreach ( $defs as $id => $g ) {
            $keywords = isset( $g['keywords'] ) ? $g['keywords'] : '';
            $top = 0;
            foreach ( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) as $key ) {
                $key = remove_accents( strtolower( $key ) );
                if ( $key === '' || strlen( $key ) <= $top ) continue;
                // Short keywords need word boundaries — the same rule the FAQ matcher uses.
                // Plain strpos() made "mwen kontan" open the account guide (kont), "shopping"
                // open the PIN guide (pin) and "kontakte" match kont as well.
                $hit = strlen( $key ) >= 5
                    ? ( strpos( $normalized, $key ) !== false )
                    : (bool) preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/', $normalized );
                if ( $hit ) $top = strlen( $key );
            }
            if ( ! $top ) continue;
            $scores[ $id ] = $top;
            if ( $top > $score ) { $best = $id; $score = $top; }
        }
        $floor = max( 3, (int) $min );
        if ( $score < $floor ) return '';
        foreach ( (array) ( $defs[ $best ]['yield_to'] ?? array() ) as $other ) {
            if ( isset( $scores[ $other ] ) && $scores[ $other ] >= $floor ) return (string) $other;
        }
        return $best;
    }
}
