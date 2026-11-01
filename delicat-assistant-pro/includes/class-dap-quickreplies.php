<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Agent quick messages (v6.4.0).
 *
 * Before this, "Réponses rapides" was one settings textarea, one reply per line, that
 * shipped **empty** — so the `<select>` in the composer never even rendered and every
 * agent started from a blank page. There was no grouping, no language, and no way to
 * avoid retyping the customer's name and the current wait time on every message.
 *
 * This ships a working library out of the box: grouped by moment in the conversation,
 * written in the three languages the store serves, with placeholders resolved against
 * the open thread before the text reaches the composer. Agents keep full control —
 * anything typed in Settings is appended, and a group can be switched off.
 *
 * Placeholders resolved at render time:
 *   {name}    the customer's name on this conversation, or a neutral form of address
 *   {agent}   the signed-in agent's display name
 *   {store}   the brand name from settings
 *   {eta}     the current response estimate, following the online/away/offline status
 *   {wallet}  {shop}  {account}   store URLs
 */
class DSA2_QuickReplies {

    const VERSION = '1.0.0';

    /** Groups in the order an agent moves through a conversation. */
    public static function groups() {
        return array(
            'open'     => array( 'icon' => '👋', 'fr' => 'Ouverture',   'en' => 'Opening',    'ht' => 'Louvri' ),
            'order'    => array( 'icon' => '🧾', 'fr' => 'Commandes',   'en' => 'Orders',     'ht' => 'Kòmand' ),
            'payment'  => array( 'icon' => '💳', 'fr' => 'Paiement',    'en' => 'Payment',    'ht' => 'Peman' ),
            'topup'    => array( 'icon' => '⚡', 'fr' => 'Recharge auto', 'en' => 'Auto top-up', 'ht' => 'Rechaj otomatik' ),
            'pin'      => array( 'icon' => '🔑', 'fr' => 'PIN & codes', 'en' => 'PIN & codes','ht' => 'PIN ak kòd' ),
            'shipping' => array( 'icon' => '📦', 'fr' => 'Livraison',   'en' => 'Delivery',   'ht' => 'Livrezon' ),
            'wait'     => array( 'icon' => '⏳', 'fr' => 'Patience',    'en' => 'Holding',    'ht' => 'Tann' ),
            'sorry'    => array( 'icon' => '🙏', 'fr' => 'Excuses',     'en' => 'Apologies',  'ht' => 'Eskiz' ),
            'close'    => array( 'icon' => '✅', 'fr' => 'Clôture',     'en' => 'Closing',    'ht' => 'Fèmti' ),
        );
    }

    /**
     * The shipped library. Each entry: group, short label, and the message in fr/en/ht.
     * Deliberately written to Delicat's actual process — MonCash/NatCash → TransCode →
     * wallet → purchase, PINs inside the account — so an agent never has to correct it.
     */
    private static function library() {
        return array(

            // ---- opening -------------------------------------------------------
            array( 'g' => 'open', 'k' => 'hello', 'l' => array( 'Bonjour', 'Hello', 'Bonjou' ),
                'fr' => "Bonjour {name}, ici {agent} de {store} 👋 Je prends votre conversation en charge. Comment puis-je vous aider ?",
                'en' => "Hello {name}, this is {agent} from {store} 👋 I'm taking over your conversation. How can I help?",
                'ht' => "Bonjou {name}, se {agent} nan {store} 👋 M ap pran konvèsasyon w lan. Kijan m ka ede w?" ),
            array( 'g' => 'open', 'k' => 'took_over', 'l' => array( 'Je reprends', 'Taking over', 'M ap kontinye' ),
                'fr' => "Je reprends la suite après l'assistant {name}. J'ai lu toute la conversation, inutile de répéter.",
                'en' => "I'm picking up from the assistant {name}. I've read the whole thread, no need to repeat anything.",
                'ht' => "M ap kontinye apre asistan an {name}. M li tout konvèsasyon an, ou pa bezwen repete." ),
            array( 'g' => 'open', 'k' => 'ask_details', 'l' => array( 'Demander le n° de commande', 'Ask for order number', 'Mande nimewo kòmand' ),
                'fr' => "Pour aller vite, envoyez-moi votre numéro de commande et l'adresse e-mail utilisée à l'achat. Je vérifie immédiatement.",
                'en' => "To move fast, send me your order number and the e-mail used at checkout. I'll check right away.",
                'ht' => "Pou n ale vit, voye nimewo kòmand ou ak imel ou te itilize a. M ap verifye touswit." ),
            array( 'g' => 'open', 'k' => 'ask_screenshot', 'l' => array( 'Demander une capture', 'Ask for a screenshot', 'Mande yon foto' ),
                'fr' => "Pouvez-vous m'envoyer une capture d'écran du message ou de l'erreur ? Le bouton 📎 dans le chat permet de joindre une image.",
                'en' => "Could you send me a screenshot of the message or the error? The 📎 button in the chat attaches an image.",
                'ht' => "Èske w ka voye yon foto ekran mesaj la oswa erè a? Bouton 📎 nan chat la pèmèt ou mete yon imaj." ),

            // ---- orders --------------------------------------------------------
            array( 'g' => 'order', 'k' => 'checking', 'l' => array( 'Je vérifie', 'Checking now', 'M ap verifye' ),
                'fr' => "Je vérifie votre commande maintenant, {name}. Restez en ligne un instant.",
                'en' => "I'm checking your order now, {name}. Stay with me a moment.",
                'ht' => "M ap verifye kòmand ou kounye a, {name}. Rete la yon ti moman." ),
            array( 'g' => 'order', 'k' => 'processing', 'l' => array( 'Commande en traitement', 'Order processing', 'Kòmand ap trete' ),
                'fr' => "Votre commande est bien enregistrée et en cours de traitement. Dès qu'elle passe en « terminée », le produit apparaît dans « Mon compte » — pas par e-mail.",
                'en' => "Your order is registered and being processed. As soon as it turns \"completed\", the product appears in \"My account\" — not by e-mail.",
                'ht' => "Kòmand ou anrejistre epi l ap trete. Kou l pase nan « fini », pwodwi a parèt nan « Kont mwen » — pa nan imel." ),
            array( 'g' => 'order', 'k' => 'delivered', 'l' => array( 'Commande livrée', 'Order delivered', 'Kòmand livre' ),
                'fr' => "C'est livré ✅ Ouvrez « Mon compte » et vous verrez le produit. Si vous ne le voyez pas, vérifiez que vous êtes connecté avec le compte utilisé pour l'achat.",
                'en' => "It's delivered ✅ Open \"My account\" and you'll see the product. If it isn't there, check you're signed in with the account used for the purchase.",
                'ht' => "Li livre ✅ Louvri « Kont mwen » epi w ap wè pwodwi a. Si w pa wè l, verifye ou konekte ak kont ou te itilize pou acha a." ),
            array( 'g' => 'order', 'k' => 'wrong_account', 'l' => array( 'Mauvais compte', 'Wrong account', 'Move kont' ),
                'fr' => "La commande est bien passée, mais sur un autre compte que celui où vous êtes connecté. Déconnectez-vous et reconnectez-vous avec l'adresse utilisée au paiement.",
                'en' => "The order went through, but on a different account from the one you're signed into. Sign out and back in with the address used at payment.",
                'ht' => "Kòmand lan pase, men sou yon lòt kont pase sa w konekte a. Dekonekte epi rekonekte ak adrès ou te itilize nan peman an." ),

            // ---- payment -------------------------------------------------------
            array( 'g' => 'payment', 'k' => 'transcode', 'l' => array( 'Expliquer le TransCode', 'Explain TransCode', 'Esplike TransCode' ),
                'fr' => "Après votre paiement MonCash ou NatCash, vous recevez un SMS contenant un TransCode. Entrez ce code sur la page de recharge : le montant est ajouté à votre crédit boutique immédiatement, puis vous payez depuis ce solde.",
                'en' => "After your MonCash or NatCash payment you get an SMS with a TransCode. Enter that code on the top-up page: the amount is added to your store credit immediately, then you pay from that balance.",
                'ht' => "Apre ou peye ak MonCash oswa NatCash, ou resevwa yon SMS ak yon TransCode. Antre kòd sa a sou paj rechaj la: montan an ajoute nan kredi boutik ou touswit, epi ou peye ak balans sa a." ),
            array( 'g' => 'payment', 'k' => 'not_credited', 'l' => array( 'Solde non crédité', 'Balance not credited', 'Balans pa kredite' ),
                'fr' => "N'effectuez pas de second paiement. Envoyez-moi une capture du SMS MonCash/NatCash avec le TransCode et l'heure du paiement : je vérifie la transaction et crédite manuellement si elle est bien arrivée.",
                'en' => "Don't make a second payment. Send me a screenshot of the MonCash/NatCash SMS with the TransCode and the time: I'll check the transaction and credit manually if it did arrive.",
                'ht' => "Pa fè yon dezyèm peman. Voye yon foto SMS MonCash/NatCash la ak TransCode a ak lè a: m ap verifye tranzaksyon an epi kredite l alamen si li rive." ),
            array( 'g' => 'payment', 'k' => 'credited', 'l' => array( 'Solde crédité', 'Balance credited', 'Balans kredite' ),
                'fr' => "C'est crédité ✅ Votre solde est à jour dans « Mon compte ». Vous pouvez finaliser votre achat maintenant.",
                'en' => "Credited ✅ Your balance is up to date in \"My account\". You can complete your purchase now.",
                'ht' => "Li kredite ✅ Balans ou ajou nan « Kont mwen ». Ou ka fini acha w kounye a." ),
            array( 'g' => 'payment', 'k' => 'methods', 'l' => array( 'Moyens de paiement', 'Payment methods', 'Mwayen peman' ),
                'fr' => "{store} fonctionne avec MonCash et NatCash. Vous payez le montant, vous entrez le TransCode reçu par SMS, le montant est ajouté à votre crédit boutique, puis vous achetez depuis ce solde.",
                'en' => "{store} works with MonCash and NatCash. You pay the amount, enter the TransCode from the SMS, the amount is added to your store credit, then you buy from that balance.",
                'ht' => "{store} travay ak MonCash ak NatCash. Ou peye montan an, ou antre TransCode SMS la, montan an ajoute nan kredi boutik ou, epi ou achte ak balans sa a." ),

            // ---- automatic top-up ----------------------------------------------
            array( 'g' => 'topup', 'k' => 'how', 'l' => array( 'Expliquer la recharge auto', 'Explain auto top-up', 'Esplike rechaj otomatik' ),
                'fr' => "Pour les jeux, tout est automatique {name} : vous choisissez le pack, vous entrez votre Player ID, le pseudo s'affiche pour vérification, puis vous payez avec votre crédit boutique. Le crédit arrive dans le jeu en moins de 60 secondes — il n'y a aucun code à saisir.",
                'en' => "For games everything is automatic {name}: you pick the pack, enter your Player ID, the nickname shows for you to check, then you pay with your store credit. The credit lands in the game in under 60 seconds — there is no code to enter.",
                'ht' => "Pou jwèt yo tout bagay otomatik {name}: ou chwazi pak la, ou antre Player ID ou, non an parèt pou w verifye, epi ou peye ak kredi boutik ou. Kredi a rive nan jwèt la nan mwens pase 60 segonn — pa gen okenn kòd pou antre." ),
            array( 'g' => 'topup', 'k' => 'ask_id', 'l' => array( 'Demander le Player ID', 'Ask for the Player ID', 'Mande Player ID' ),
                'fr' => "Envoyez-moi votre Player ID et le pseudo exact affiché dans le jeu, ainsi que le pack souhaité. Je vérifie avant d'envoyer la recharge — nous ne demandons jamais votre mot de passe.",
                'en' => "Send me your Player ID and the exact nickname shown in the game, plus the pack you want. I check before sending the top-up — we never ask for your password.",
                'ht' => "Voye ban mwen Player ID ou ak non egzak ki parèt nan jwèt la, ak pak ou vle a. M ap verifye anvan m voye rechaj la — nou pa janm mande modpas ou." ),
            array( 'g' => 'topup', 'k' => 'ff_region', 'l' => array( 'Free Fire : quelle région', 'Free Fire: which region', 'Free Fire: ki rejyon' ),
                'fr' => "Free Fire existe ici en LATAM, BR, EUROPE, ID et MENA. La recharge doit correspondre à la région de votre compte, sinon elle échoue. Donnez-moi votre Player ID et je confirme la bonne version avant que vous payiez.",
                'en' => "Free Fire comes in LATAM, BR, EUROPE, ID and MENA here. The top-up has to match your account's region or it fails. Send me your Player ID and I'll confirm the right one before you pay.",
                'ht' => "Free Fire genyen LATAM, BR, EUROPE, ID ak MENA isit la. Rechaj la dwe koresponn ak rejyon kont ou, sinon li p ap pase. Ban m Player ID ou epi m ap konfime bon an anvan ou peye." ),
            array( 'g' => 'topup', 'k' => 'restart', 'l' => array( 'Relancer le jeu', 'Restart the game', 'Rilanse jwèt la' ),
                'fr' => "La recharge est bien partie de notre côté ✅ Fermez complètement le jeu et relancez-le : le solde ne se rafraîchit pas toujours en direct. Dites-moi si vous ne le voyez toujours pas après le redémarrage.",
                'en' => "The top-up has gone through on our side ✅ Close the game completely and reopen it: the balance doesn't always refresh live. Tell me if you still don't see it after the restart.",
                'ht' => "Rechaj la pati bò kote nou ✅ Fèmen jwèt la nèt epi rilanse l: balans lan pa toujou rafrechi an dirèk. Di m si w toujou pa wè l apre w rilanse." ),
            array( 'g' => 'topup', 'k' => 'not_credited', 'l' => array( 'Recharge non créditée', 'Top-up not credited', 'Rechaj pa kredite' ),
                'fr' => "Ne repassez pas de commande, cela créerait un double paiement. Envoyez-moi le numéro de commande et le Player ID utilisé : je vérifie la transaction chez le fournisseur et je la relance ou la rembourse.",
                'en' => "Don't place the order again, that would create a double payment. Send me the order number and the Player ID you used: I'll check the transaction with the supplier and re-send or refund it.",
                'ht' => "Pa pase kòmand lan ankò, sa ta fè yon doub peman. Voye ban mwen nimewo kòmand lan ak Player ID ou te itilize a: m ap verifye tranzaksyon an ak founisè a epi voye l ankò oswa ranbouse l." ),
            array( 'g' => 'topup', 'k' => 'no_code', 'l' => array( 'Pas de code pour les jeux', 'No code for games', 'Pa gen kòd pou jwèt' ),
                'fr' => "Il n'y a pas de code pour ce produit : la recharge est créditée directement sur votre compte de jeu via le Player ID. Les codes concernent les cartes cadeaux et le produit FREE FIRE PIN, vendus séparément.",
                'en' => "There is no code for this product: the top-up is credited straight to your game account via the Player ID. Codes apply to the gift cards and the separate FREE FIRE PIN product.",
                'ht' => "Pa gen kòd pou pwodwi sa a: yo kredite rechaj la dirèk nan kont jwèt ou atravè Player ID a. Kòd yo se pou kat kado yo ak pwodwi FREE FIRE PIN an, ki vann apa." ),

            // ---- PIN -----------------------------------------------------------
            array( 'g' => 'pin', 'k' => 'where', 'l' => array( 'Où trouver le PIN', 'Where the PIN is', 'Kote PIN lan ye' ),
                'fr' => "Vos PIN sont livrés dans votre compte, jamais par e-mail : ouvrez « Mon compte » puis la section de vos PIN, en étant connecté avec le compte de l'achat.",
                'en' => "Your PINs are delivered inside your account, never by e-mail: open \"My account\" then your PIN section, signed in with the purchase account.",
                'ht' => "PIN ou yo livre nan kont ou, pa janm nan imel: louvri « Kont mwen » epi seksyon PIN ou yo, konekte ak kont acha a." ),
            array( 'g' => 'pin', 'k' => 'released', 'l' => array( 'PIN débloqué', 'PIN released', 'PIN lage' ),
                'fr' => "Je viens de débloquer votre PIN manuellement ✅ Rafraîchissez « Mon compte » et il apparaît. Ne partagez ce code avec personne avant de l'utiliser.",
                'en' => "I've released your PIN manually ✅ Refresh \"My account\" and it will be there. Don't share this code with anyone before redeeming it.",
                'ht' => "M fèk lage PIN ou alamen ✅ Rafrechi « Kont mwen » epi l ap parèt. Pa pataje kòd sa a ak pèsonn anvan ou itilize l." ),
            array( 'g' => 'pin', 'k' => 'invalid', 'l' => array( 'PIN refusé', 'PIN rejected', 'PIN refize' ),
                'fr' => "Avant tout : ne publiez le code nulle part. Vérifiez les caractères qui se ressemblent (0/O, 1/I) et la région choisie. Envoyez-moi le numéro de commande et la capture de l'erreur, je vérifie auprès du fournisseur.",
                'en' => "First: don't post the code anywhere. Check look-alike characters (0/O, 1/I) and the region you selected. Send me the order number and the error screenshot and I'll check with the supplier.",
                'ht' => "Anvan tout: pa mete kòd la okenn kote. Tcheke karaktè ki sanble yo (0/O, 1/I) ak rejyon ou chwazi a. Voye nimewo kòmand lan ak foto erè a, m ap verifye ak founisè a." ),
            array( 'g' => 'pin', 'k' => 'player_id', 'l' => array( 'Player ID', 'Player ID', 'Player ID' ),
                'fr' => "Votre Player ID se trouve dans le profil du jeu, sous votre pseudo. Copiez-le exactement, sans espace, et renvoyez-le-moi pour que je vérifie avant l'envoi.",
                'en' => "Your Player ID is in the game profile, under your nickname. Copy it exactly, no spaces, and send it to me so I can verify before we push the top-up.",
                'ht' => "Player ID ou nan pwofil jwèt la, anba non ou. Kopye l egzakteman, san espas, epi voye l ban mwen pou m verifye anvan nou voye rechaj la." ),

            // ---- delivery ------------------------------------------------------
            array( 'g' => 'shipping', 'k' => 'zone', 'l' => array( 'Demander la commune', 'Ask for the area', 'Mande komin nan' ),
                'fr' => "Pour un article physique, dites-moi votre commune : je vous confirme le délai exact et les frais de livraison avant que vous payiez quoi que ce soit.",
                'en' => "For a physical item, tell me your commune: I'll confirm the exact delay and delivery fee before you pay anything.",
                'ht' => "Pou yon atik fizik, di m komin ou: m ap konfime delè egzak la ak frè livrezon an anvan ou peye anyen." ),
            array( 'g' => 'shipping', 'k' => 'on_the_way', 'l' => array( 'Colis en route', 'Package on the way', 'Pake a sou wout' ),
                'fr' => "Votre colis est en route 🚚 Gardez votre téléphone joignable, le livreur vous appelle avant d'arriver.",
                'en' => "Your package is on the way 🚚 Keep your phone reachable, the courier calls before arriving.",
                'ht' => "Pake w sou wout 🚚 Kenbe telefòn ou disponib, moun k ap livre a ap rele w anvan l rive." ),
            array( 'g' => 'shipping', 'k' => 'defective', 'l' => array( 'Article défectueux', 'Defective item', 'Atik defektye' ),
                'fr' => "Ne continuez pas à l'utiliser. Envoyez-moi une photo du défaut et votre numéro de commande aujourd'hui même : je lance le remplacement.",
                'en' => "Stop using it. Send me a photo of the fault and your order number today: I'll start the replacement.",
                'ht' => "Sispann itilize l. Voye yon foto defo a ak nimewo kòmand ou jodi a menm: m ap kòmanse ranplasman an." ),

            // ---- holding -------------------------------------------------------
            array( 'g' => 'wait', 'k' => 'one_moment', 'l' => array( 'Un instant', 'One moment', 'Yon ti moman' ),
                'fr' => "Un instant {name}, je vérifie et je reviens vers vous tout de suite.",
                'en' => "One moment {name}, I'm checking and I'll be right back.",
                'ht' => "Yon ti moman {name}, m ap verifye epi m tounen ba w touswit." ),
            array( 'g' => 'wait', 'k' => 'still_here', 'l' => array( 'Toujours là', 'Still on it', 'M la toujou' ),
                'fr' => "Je suis toujours sur votre dossier, {name}, merci de patienter encore un peu.",
                'en' => "I'm still on your case, {name}, thanks for bearing with me a little longer.",
                'ht' => "M toujou sou dosye w, {name}, mèsi paske w pran yon ti pasyans ankò." ),
            array( 'g' => 'wait', 'k' => 'escalate', 'l' => array( 'Transmis à l\'équipe', 'Escalated', 'Voye bay ekip la' ),
                'fr' => "Je transmets votre dossier à l'équipe technique. Réponse attendue sous {eta}. Vous recevez une notification ici dès qu'il y a du nouveau.",
                'en' => "I'm escalating your case to the technical team. Expected reply within {eta}. You'll get a notification right here as soon as there's news.",
                'ht' => "M ap voye dosye w bay ekip teknik la. Repons nan {eta}. W ap resevwa yon notifikasyon la a kou gen nouvo." ),
            array( 'g' => 'wait', 'k' => 'offline', 'l' => array( 'Hors ligne', 'Outside hours', 'Andeyò lè' ),
                'fr' => "Nous sommes en dehors des heures d'ouverture. Votre message est enregistré et un agent y répond dès la réouverture — délai indicatif : {eta}.",
                'en' => "We're outside opening hours. Your message is saved and an agent replies as soon as we reopen — indicative wait: {eta}.",
                'ht' => "Nou andeyò lè travay. Mesaj ou anrejistre epi yon ajan ap reponn kou nou relouvri — delè: {eta}." ),

            // ---- apologies -----------------------------------------------------
            array( 'g' => 'sorry', 'k' => 'delay', 'l' => array( 'Excuses pour l\'attente', 'Sorry for the wait', 'Padon pou tann nan' ),
                'fr' => "Je suis vraiment désolé pour l'attente {name}. Ce n'est pas le service que nous voulons vous donner. Je m'occupe de vous maintenant.",
                'en' => "I'm very sorry for the wait {name}. That's not the service we want to give you. I'm on it now.",
                'ht' => "M regrèt anpil pou tann nan {name}. Se pa sèvis nou vle ba w. M ap okipe w kounye a." ),
            array( 'g' => 'sorry', 'k' => 'our_error', 'l' => array( 'Erreur de notre côté', 'Our mistake', 'Erè bò kote nou' ),
                'fr' => "L'erreur vient de chez nous et je m'en excuse. Voici ce que je fais pour corriger :",
                'en' => "The mistake is on our side and I apologise for it. Here's what I'm doing to fix it:",
                'ht' => "Erè a soti bò kote nou epi m mande w padon. Men sa m ap fè pou korije l:" ),
            array( 'g' => 'sorry', 'k' => 'cannot', 'l' => array( 'Ne peut pas être fait', 'Cannot be done', 'Nou pa ka fè l' ),
                'fr' => "Je préfère être honnête : ce n'est pas quelque chose que nous pouvons faire. Voici l'alternative la plus proche que je peux vous proposer :",
                'en' => "I'd rather be straight with you: that isn't something we can do. Here's the closest alternative I can offer:",
                'ht' => "M pito di w laverite: sa se pa yon bagay nou ka fè. Men altènativ ki pi pre a m ka ofri w:" ),

            // ---- closing -------------------------------------------------------
            array( 'g' => 'close', 'k' => 'anything_else', 'l' => array( 'Autre chose ?', 'Anything else?', 'Lòt bagay?' ),
                'fr' => "C'est réglé de mon côté ✅ Y a-t-il autre chose que je puisse faire pour vous {name} ?",
                'en' => "That's sorted on my side ✅ Is there anything else I can do for you {name}?",
                'ht' => "Sa regle bò kote m ✅ Èske gen lòt bagay m ka fè pou ou {name}?" ),
            array( 'g' => 'close', 'k' => 'thanks_bye', 'l' => array( 'Clôture', 'Sign-off', 'Fèmen' ),
                'fr' => "Merci de votre confiance {name} 🙏 Le chat reste ouvert : écrivez-nous quand vous voulez. Bonne journée !",
                'en' => "Thank you for your trust {name} 🙏 The chat stays open — write to us any time. Have a great day!",
                'ht' => "Mèsi pou konfyans ou {name} 🙏 Chat la rete louvri: ekri nou lè w vle. Pase yon bèl jounen!" ),
            array( 'g' => 'close', 'k' => 'no_reply', 'l' => array( 'Sans réponse', 'No reply', 'Pa gen repons' ),
                'fr' => "Je n'ai pas de nouvelles de votre part, je clos donc la conversation pour l'instant. Elle reste consultable et vous pouvez répondre ici à tout moment pour la rouvrir.",
                'en' => "I haven't heard back, so I'm closing this for now. It stays readable and you can reply here any time to reopen it.",
                'ht' => "M pa jwenn nouvèl ou, donk m ap fèmen sa pou kounye a. L ap rete la epi ou ka reponn isit la nenpòt lè pou relouvri l." ),
            array( 'g' => 'close', 'k' => 'ask_rating', 'l' => array( 'Demander un avis', 'Ask for a rating', 'Mande yon nòt' ),
                'fr' => "Si vous avez trente secondes, dites-nous comment s'est passée cette conversation — ça nous aide beaucoup à progresser 🙏",
                'en' => "If you have thirty seconds, tell us how this went — it really helps us improve 🙏",
                'ht' => "Si w gen trant segonn, di nou kijan konvèsasyon sa a te ye — sa ede nou amelyore anpil 🙏" ),
        );
    }

    /** Groups the agent has switched off, from settings. */
    private static function disabled_groups() {
        $s = get_option( 'dap_settings', array() );
        $raw = (string) ( $s['quick_groups_off'] ?? '' );
        return array_filter( array_map( 'sanitize_key', explode( ',', $raw ) ) );
    }

    public static function enabled() {
        $s = get_option( 'dap_settings', array() );
        return ! isset( $s['quick_replies_enabled'] ) || ! empty( $s['quick_replies_enabled'] );
    }

    /**
     * Values for the placeholder tokens, resolved against the open conversation.
     * Everything is plain text — the composer inserts into a <textarea>.
     */
    private static function tokens( $conversation = null ) {
        $lang = $conversation && ! empty( $conversation->language ) ? sanitize_key( $conversation->language ) : 'fr';
        $name = $conversation ? trim( (string) ( $conversation->customer_name ?? '' ) ) : '';
        if ( '' === $name || preg_match( '/^(visiteur|visitor|guest|client)\b/i', $name ) ) {
            // No usable name: address the customer without a placeholder scar in the text.
            $name = ( 'en' === $lang ) ? 'there' : ( 'ht' === $lang ? '' : '' );
        }
        $user = wp_get_current_user();
        return array(
            '{name}'    => $name,
            '{agent}'   => $user && $user->display_name ? $user->display_name : 'Delicat',
            '{store}'   => class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_name() : 'Delicat Store',
            '{eta}'     => class_exists( 'DSA2_Data', false ) ? DSA2_Data::agent_eta() : '',
            '{wallet}'  => class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_url( 'wallet' ) : '',
            '{shop}'    => class_exists( 'DSA2_Data', false ) ? DSA2_Data::store_url( 'shop' ) : '',
            '{account}' => class_exists( 'DSA2_Data', false ) ? DSA2_Data::account_url() : '',
        );
    }

    /**
     * Tidies the seams an empty {name} leaves behind.
     *
     * An anonymous visitor has no name, so "Bonjour {name}, ici" must not become
     * "Bonjour , ici" and "maintenant, {name}." must not become "maintenant,.".
     *
     * The token is removed together with the space in front of it rather than replaced
     * by an empty string, which keeps the spacing on both sides correct: "pour vous
     * {name} ?" gives the French "pour vous ?" while the English "for you {name}?"
     * gives "for you?". A blanket rule stripping whitespace before punctuation would
     * have eaten the space French typography requires before ? ! ; and :.
     */
    private static function render( $text, $tokens ) {
        $out = (string) $text;
        if ( '' === trim( (string) ( $tokens['{name}'] ?? '' ) ) ) {
            $out = preg_replace( '/\s*\{name\}/u', '', $out );
        }
        $out = strtr( $out, $tokens );
        $out = preg_replace( '/,\s*([?!.])/u', '$1', $out );  // "maintenant,." -> "maintenant."
        $out = preg_replace( '/,\s*,/u', ',', $out );
        $out = preg_replace( '/^\s*,\s*/u', '', $out );
        $out = preg_replace( '/\s{2,}/u', ' ', $out );
        return trim( $out );
    }

    /**
     * The list the composer renders.
     *
     * @param object|null $conversation Open thread, for placeholders and language.
     * @param string      $lang         Force a language; defaults to the thread's.
     * @return array<int,array{group:string,label:string,text:string}>
     */
    public static function all( $conversation = null, $lang = '' ) {
        if ( ! self::enabled() ) return array();
        $lang = $lang ? sanitize_key( $lang ) : ( $conversation && ! empty( $conversation->language ) ? sanitize_key( $conversation->language ) : 'fr' );
        if ( ! in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ) $lang = 'fr';
        $tokens = self::tokens( $conversation );
        $off = self::disabled_groups();
        $li = ( 'en' === $lang ) ? 1 : ( 'ht' === $lang ? 2 : 0 );

        $out = array();
        foreach ( self::library() as $row ) {
            if ( in_array( $row['g'], $off, true ) ) continue;
            $text = isset( $row[ $lang ] ) ? $row[ $lang ] : $row['fr'];
            $out[] = array(
                'group' => $row['g'],
                'key'   => $row['k'],
                'label' => isset( $row['l'][ $li ] ) ? $row['l'][ $li ] : $row['l'][0],
                'text'  => self::render( $text, $tokens ),
            );
        }

        // Anything the agent typed in Settings is appended as a custom group, so a
        // store-specific reply is never lost behind the shipped library.
        $s = get_option( 'dap_settings', array() );
        $custom = array_filter( array_map( 'trim', preg_split( '/\R/u', (string) ( $s['canned_replies'] ?? '' ) ) ) );
        foreach ( array_slice( $custom, 0, 40 ) as $line ) {
            // "Label = text" is supported; a bare line becomes its own label.
            if ( false !== strpos( $line, '=' ) ) {
                list( $label, $text ) = array_map( 'trim', explode( '=', $line, 2 ) );
            } else {
                $label = DSA2_Compat::substr( $line, 0, 34 );
                $text  = $line;
            }
            if ( '' === $text ) continue;
            $out[] = array(
                'group' => 'custom',
                'key'   => 'custom_' . substr( md5( $line ), 0, 6 ),
                'label' => $label,
                'text'  => self::render( $text, $tokens ),
            );
        }
        return $out;
    }

    /** Group metadata for the tabs, including "custom" when the agent added any. */
    public static function group_tabs( $replies, $lang = 'fr' ) {
        $used = array();
        foreach ( $replies as $r ) $used[ $r['group'] ] = true;
        $tabs = array();
        foreach ( self::groups() as $key => $meta ) {
            if ( empty( $used[ $key ] ) ) continue;
            $tabs[ $key ] = $meta['icon'] . ' ' . ( isset( $meta[ $lang ] ) ? $meta[ $lang ] : $meta['fr'] );
        }
        if ( ! empty( $used['custom'] ) ) {
            $tabs['custom'] = '⭐ ' . ( 'en' === $lang ? 'Custom' : ( 'ht' === $lang ? 'Pa w' : 'Perso' ) );
        }
        return $tabs;
    }

    public static function stats() {
        $groups = count( self::groups() );
        $count  = count( self::library() );
        return array( 'replies' => $count, 'groups' => $groups, 'languages' => 3, 'total' => $count * 3 );
    }
}
