=== Delicat Assistant Pro ===
Contributors: delicat-store
Tags: woocommerce, customer support, live chat, chatbot, whatsapp
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 7.1.0

A multilingual WooCommerce help center with a private agent inbox, automated answers, mobile-app support, tickets and WhatsApp handoff.

== Description ==

Delicat Assistant Pro gives customers three clear ways to get help:

* Instant answers from the automated assistant.
* A private conversation with the Delicat support team.
* WhatsApp, when enabled by an administrator.

The agent inbox prioritizes human-support messages while offering an on-demand view of the automated assistant history that led to each handoff. Customers keep one conversation across the website and the native app after linking their account.

Features include French, English and Haitian Creole; persistent message composers and drafts; retry-safe message delivery; two-way read receipts and typing indicators; private team notes; WooCommerce orders, payment status, wallet balances and reward points; urgent and overdue inbox queues; satisfaction prompts; secure image attachments; assignment-aware access controls; signed webhooks; and Android push notifications.

== Installation ==

1. In WordPress, open Plugins > Add New > Upload Plugin.
2. Select the `delicat-assistant-pro-7.1.0.zip` file, choose « Replace current with uploaded », and activate it.
3. Open Delicat Assistant > Settings to configure the brand, team availability, WhatsApp and notifications.
4. Add users to the **Agent support Delicat** role when they should use the inbox without receiving store-wide administration rights.

The floating help center is enabled from Settings. Use `[delicat_assistant]` for an embedded support page.

== Frequently Asked Questions ==

= Will upgrading delete existing conversations? =

No. Version 5 migrates existing messages into assistant and agent channels and keeps conversations, tickets and mobile sessions.

= Can an agent see the automated conversation before a human handoff? =

Yes. The human support timeline remains focused, and an authorized agent can open the separate handoff context panel to review recent automated exchanges without mixing both channels.

= Does WhatsApp appear when no number is configured? =

No. WhatsApp is shown only when it is enabled and a valid number has been saved.

== Changelog ==

= 7.1.0 =
* Suivre une commande dans le chat : un client connecté tape « #23170 », « commande 23170 », « kòmand 23170 » ou
  le numéro seul et voit le vrai statut de SA commande : statut, total, date, articles, moyen de paiement, ce qui
  se passe ensuite, « Voir la commande » et « Partager le reçu » (WhatsApp ou le menu de partage du téléphone).
  Le reçu partagé ne contient ni adresse, ni téléphone, ni e-mail, ni code PIN, ni Player ID, ni note.
* Sécurité : la commande d'un autre client reçoit exactement la même réponse qu'un numéro qui n'existe pas ; un
  visiteur non connecté est invité à se connecter (ou à utiliser le formulaire numéro + e-mail) ; 30 recherches
  par heure et par compte au plus (300 par adresse réseau, pour les clients qui partagent l'adresse de leur
  opérateur) ; seules les vraies commandes de la boutique sont montrées ; le total affiché tient compte des
  remboursements.
* Sécurité : le formulaire « numéro de commande + e-mail ou téléphone » se bloque une heure après 5 essais faux
  sur un même numéro, quelle que soit l'adresse réseau ; les réponses de l'API client du chat portent
  « Cache-Control: private, no-store » (jamais gardées par un cache) ; un « oui » ne répond qu'à la question
  juste avant ; un nombre seul de plus de 7 chiffres (Player ID, téléphone) n'est plus pris pour une commande.
* « Où est ma commande ? » (client connecté) : la carte de sa dernière commande.
* Conversation plus naturelle : « Bonjour, je n'ai pas reçu mes diamants » reçoit la réponse sur les diamants (pas
  seulement « Bonjour ») ; « Est-ce que ça va arriver ? », « J'attends depuis 3 jours », « Il n'y a plus de
  diamants ? » ne sont plus pris pour « ça va », « attendez » ou « à plus » ; « Yo pa reponn mwen » n'est plus
  un bonjour ; « call me back » ou « m rele pou… » ne sont plus enregistrés comme prénom.
* Réponses plus justes : « PIN pas reçu », « diamants pas arrivés », « code invalide », « mauvais Player ID »
  reçoivent la réponse précise avant le guide général de paiement ; « recharger mon compte Free Fire » ouvre la
  recharge du jeu ; « parler à un humain » appelle un agent, « es-tu un robot ? » explique l'assistant, et le
  mot « moun » seul n'appelle plus d'agent ; un « oui » après « voulez-vous un agent ? » vous connecte ;
  « J'attends toujours » appelle vraiment un agent ; plus de « votre commande ce produit ».
* Nouvelles réponses : Programme Revendeur (niveaux et minimums réels, et votre niveau si vous êtes revendeur),
  Parrainage (lien vers votre code), Envoyer de l'argent (code personnel, montants et frais réels), sécurité du
  compte (Passkey, appareils, jamais de mot de passe ni de code à l'équipe).
* « Je n'ai pas compris » propose des raccourcis (commande, recharge, PIN, guides) en plus de l'équipe.
* Chat : la tuile « Recharger un jeu » ouvre la recharge du jeu (elle ouvrait le crédit boutique) ; demander un
  agent depuis l'onglet Assistant ouvre l'onglet Équipe où la réponse arrive.
* Technique : nouveau module includes/class-dap-brain.php ; une même réponse n'est plus masquée pendant 2 minutes
  (15 s, contre les doubles envois) ; app.js reconstruit.

= 7.0.1 =
* En français : le site ne se bloque plus après la fermeture du chat. En 7.0.0, la fenêtre fermée restait invisible au-dessus de la page et recevait les touchers : sur téléphone plus rien ne répondait, sur ordinateur le bouton du chat ne se rouvrait plus. Fermé veut maintenant dire retiré de la page (après l'animation de sortie).
* Le bouton du chat ne cache plus la barre d'achat d'une fiche produit (« Total · Ajouter au panier · Acheter maintenant ») : il se place 12 px au-dessus, quelle que soit sa hauteur. La bulle « Besoin d'aide ? » ne s'affiche plus par-dessus une carte « Vente en direct » ni sur une fiche produit, et disparaît dès que le visiteur fait défiler ou touche la page.
* Partager un groupe : bouton Partager dans un groupe public (et dans son menu). Le lien (?dap_group=nom-du-groupe, sur n'importe quelle page du site) ouvre le chat dans ce groupe ; un visiteur qui doit d'abord se connecter retrouve le groupe ouvert après la connexion (même onglet, 15 minutes). Les salons cachés ne se partagent pas et restent réservés à leurs membres. Le lien de chaque groupe public est aussi affiché dans Groupes clients › Salons.
* Messages des groupes : un message envoyé apparaît tout de suite avec « Envoi… » ; en cas d'échec le texte revient dans la zone de saisie. Les messages déjà affichés ne sont plus redessinés à chaque nouveau message (plus de clignotement des images, la sélection de texte et le focus restent). Plusieurs messages de suite d'une même personne sont regroupés (un seul nom et un seul avatar). « Copier le texte » dans le menu d'un message. Une citation d'un message retiré n'affiche plus « : » devant « Message retiré ». Le bouton « Messages précédents » reste utilisable après un premier chargement.
* Conversation avec l'équipe : un seul « Équipe Delicat » par suite de réponses ; un message renvoyé après une erreur ne laisse plus l'ancien message d'erreur affiché.
* Fixed: after the chat was closed, its invisible window stayed over the page and took every tap (the site looked frozen on phones; the launcher could not reopen on desktop). The launcher clears the product buy bar; the nudge yields to live-sale cards and product pages and leaves on the first scroll or tap. Shareable public group links (?dap_group=slug) that survive the sign-in. Group messages: instant sending state, no full redraw on each poll, grouped runs, copy text, quote and "earlier messages" fixes.

= 7.0.0 =
* En français : assistance du site reconstruite. Sur chaque page, seulement un bouton (environ 7 Ko dans la page, aucune requête, aucun nonce ni identifiant client dans le HTML : les pages restent en cache pour tout le monde). Le chat (un seul fichier, 30 Ko compressé) se charge au premier toucher et s’ouvre en moins d’une seconde sur un téléphone en 4G lente (0,45 à 0,9 s sur le site de test, fichier non compressé), contre 1,7 à 2,1 s en 6.6.4. Les clients connectés ne chargent plus groups.css ni groups.js ; le nombre de messages non lus est vérifié au plus une fois par minute, une fois la page affichée (plus à chaque page).
* Nouveau chat isolé du thème (Shadow DOM) : le thème ne peut plus casser son affichage et il ne modifie plus le site. Couleurs, police et mode sombre suivent le Design System Delicat ; icônes Material uniquement. Quatre onglets : Accueil, Assistant (réponses automatiques), Équipe (conversation privée avec l’équipe), Communauté.
* Communauté reconstruite : les clients discutent entre eux ; « Question à l’équipe » publie la question dans le groupe, l’équipe y répond avec le badge « Équipe » (réponse qui cite la question) ou depuis Groupes clients › Questions à l’équipe ; « Continuer en privé » copie la question dans la conversation privée du client avec l’équipe. Réponses, citations, messages précédents, nom dans les groupes, signalement et modération (masquer, supprimer, épingler, sourdine, bannissement).
* Sécurité : chaque lecture d’un groupe vérifie que le client peut voir ce salon (un salon caché ou archivé n’est plus lisible par son numéro) ; seuls les membres du salon peuvent signaler ; seuls les comptes de plus de deux jours comptent pour le masquage automatique ; un message de l’équipe ou épinglé n’est jamais masqué automatiquement. La preuve de commande par téléphone doit correspondre à la fin du numéro de facturation. Démarrer une conversation avec n’importe quel client depuis la boîte de réception est réservé aux responsables. La suppression d’un compte efface aussi ses messages et images de groupe. Les images d’un salon supprimé sont effacées.
* Revue de sécurité avant publication : un client ne peut plus apparaître sous un nom de la boutique ou de l’équipe (Delicat, Support, Admin, Équipe…), même avec des espaces, des chiffres ou des lettres cyrilliques (« D e l i c a t », « Adm1n ») ni sous un nom enregistré par une ancienne version ; il apparaît alors « Client #numéro ». Un message rétabli par l’équipe n’est plus masqué de nouveau par ses anciens signalements. Une citation n’affiche plus le texte d’un message retiré. Une image supprimée par la modération est effacée du serveur. Le nombre de démarrages du chat est limité par visiteur. La suppression d’un salon depuis l’administration est réservée aux responsables. Les liens extérieurs du chat s’ouvrent sans transmettre l’adresse de la page.
* Inchangé : tables et données (conversations, messages, groupes, tickets), l’app Android de l’équipe (delicat-assistant-admin/v1), le pont de l’app Délicat Store (DSA2_Data et ses actions) et les réglages. Ouvrir le chat ne crée plus de conversation vide.
* Rebuilt website assistant. Every page carries only a launcher (about 7 KB in the page, no request, no nonce or customer id in the HTML, so pages stay cacheable). The chat (one file, 30 KB gzipped) loads on the first tap and opens in under a second on a slow-4G phone (0.45–0.9 s on the staging site, served uncompressed), versus 1.7–2.1 s in 6.6.4. Signed-in customers no longer load groups.css and groups.js; the unread count is checked at most once a minute, after the page is idle (no longer on every page view).
* New chat isolated from the theme (Shadow DOM), Delicat Design System colours, type and dark mode, Material icons only. Tabs: Home, Assistant, Team (private conversation with the team), Community.
* Rebuilt community: customer-to-customer rooms; "Ask the team" questions answered in the room (quoted, with the Team badge) or from Customer groups › Questions à l’équipe; "Continue privately" moves a member's own question into their private support conversation. Replies, earlier messages, display name, reports and moderation.
* Security: membership/visibility checked on every group read; only room members can report; only accounts older than two days count towards automatic hiding; staff and pinned messages are never hidden automatically; phone proof for the order lookup must be the end of the billing number; starting a thread with any customer from the inbox is manager-only; account deletion also removes community data; deleting a room removes its images.
* Pre-release security review: a customer can no longer appear under a store or staff name (Delicat, Support, Admin, Équipe…), including spaced, digit or Cyrillic look-alikes and names stored by older versions (shown as "Client #id"); a message restored by the team is not hidden again by its earlier reports; a quote no longer shows the text of a removed message; an image deleted by moderation is removed from the server; chat start-ups are rate-limited per visitor; deleting a room from the admin is manager-only; external links open without a referrer.
* Unchanged: tables and data, the support Android app API (delicat-assistant-admin/v1), the Délicat Store app bridge (DSA2_Data and its actions) and settings. Opening the chat no longer creates an empty conversation.

= 6.6.4 =
* En français : boutons de la FAQ du Builder (signalés par le propriétaire le 2026-10-27, trois captures d'écran). La réponse « Wallet Delicat Store » propose « 💰 Mon solde », « 💼 Recharger Wallet » et « ⚡ Guide Natcash Auto » avec des actions que l'extension n'a pas (les siennes : agent, guide, faq, rate, quick, url, whatsapp, copy) : le widget du site affichait des boutons sans effet, et l'app 5.5 / 5.6.0 ouvrait des pages absentes du site (/balance, /wallet/, /natcash) avec « … n'est pas disponible sur le site pour le moment. ». Avec Delicat App API 2.33.1 active, les boutons d'une réponse FAQ du Builder (par son numéro et par mots-clés) sont relus par Delicat_App_Chat_Actions avant d'être affichés et enregistrés : « Mon solde » devient quick:balance (la carte du solde, comme le « Mon solde » du widget), « Recharger Wallet » url:wallet (la page du crédit sur le site, l'écran « Recharger » dans l'app 5.6.1), « Guide Natcash Auto » quick:natcash (le guide Natcash Auto) ; un bouton dont rien ne peut être lu ouvre le menu d'aide. Les boutons propres à l'extension, les pages qui existent et les autres sites ne changent pas. Sans l'App API (ou avec une version plus ancienne), les boutons restent exactement tels qu'enregistrés.
* Builder FAQ buttons (reported by the owner on 2026-10-27 with three phone screenshots). The « Wallet Delicat Store » answer offers « 💰 Mon solde », « 💼 Recharger Wallet » and « ⚡ Guide Natcash Auto » with actions this plugin does not have (its own are agent, guide, faq, rate, quick, url, whatsapp, copy): the website widget drew buttons that did nothing, and app 5.5 / 5.6.0 opened pages the site does not have (/balance, /wallet/, /natcash) and said « … n'est pas disponible sur le site pour le moment. ». With Delicat App API 2.33.1 active, the buttons of a Builder FAQ answer (by its number and by keywords) are read by Delicat_App_Chat_Actions before they are shown and stored: « Mon solde » becomes quick:balance (the balance card, like the widget's own « Mon solde »), « Recharger Wallet » url:wallet (the credit page on the site, the recharge screen in app 5.6.1), « Guide Natcash Auto » quick:natcash (the Natcash Auto guide); a button nothing can be read from opens the help menu. The plugin's own buttons, pages that exist and other sites are unchanged. Without the App API (or an older one) the buttons stay exactly as stored.

= 6.6.3 =
* Google Play compliance. The app was refused once under "Play Console Requirements": a Personal developer account may not offer financial services, and the assistant's answers are shown inside the app. The website keeps selling every product; only what the assistant says, and what it shows to the app, changes.
* Removed the "Change et transfert — Convertir ou transférer votre argent via Delicat" guide in French, English and Kreyòl, with its keywords (paypal, zelle, virement, dollar, taux, transfert, échange…), so no free text can open it. Admin override lines and the dap_guides filter cannot bring it back, a stored copy is dropped when a conversation is read, and an old button for it opens the guide menu.
* New "Crédit boutique" guide in its place: add credit with MonCash or Natcash and use it for your orders; HTG, shop purchases only, never withdrawn or converted to cash. It answers "crédit boutique", "kredi boutik", "store credit", "withdraw", "cash out". Its keyword list is neutral (crédit, recharger, moncash, natcash plus those phrases); the first four still open the Recharge, MonCash and Natcash guides, which come first in the list. A complaint ("mon crédit boutique n'est pas crédité", "kredi boutik mwen pa antre", "j'ai payé mais mon crédit boutique n'a pas bougé") still opens the troubleshooting guide: the Crédit boutique guide gives way whenever that guide matches too.
* The PayPal, USDT, Binance, WISE and Meru products are no longer recognised by name, and the 78 training rows about them are gone (corpus now 1 727 rows).
* Answers served to the app never show a product the app hides (Delicat_App_Catalog::product_is_hidden() of the App API: the "Exchange" / "Finance" categories, the owner's app_hidden_categories setting, crypto words). Recommendations are cached per surface, so a list built for the website never reaches the app. Automated rows already in a conversation that show such a product (among their items, or as the product a "Souvent pris ensemble" or product card is about) or name PayPal, USDT, Binance, Zelle, WISE, Meru or crypto are left out when the app reads the history. A "Je n'ai pas bien compris" card that quotes such a question stays, without the quote, so the customer's message keeps its reply; the search words inside a product list's internal key no longer drop a valid list. Without the App API's product_is_hidden() (older App API), parent categories are checked too: Finance › Payoneer counts as Finance.
* The website still shows and sells every product; only the product filtering and the history filter above are app-only. Everything else in this release reaches the website widget too: its quick-help subtitle, the wording below, the removed "Change et transfert" guide, and the PayPal / USDT / Binance / WISE / Meru names, which the assistant no longer recognises, so an answer about them is generic ("ce produit") on both surfaces.
* Customer-facing wording now says "crédit boutique" / "store credit" / "kredi boutik" instead of portefeuille / wallet / bous, and no longer says "argent" / "money" / "lajan" or "échange" (a defective item is "remplacé"). Applies to answers, guides, small talk, agent quick messages and the training corpus.
* Paying by MonCash or Natcash is described as a payment ("Payez le montant", "Après votre paiement MonCash ou NatCash", "You pay the amount", "Peye montan an"), not as sending money, in every guide (Recharger mon solde, Natcash Auto, MonCash, Crédit boutique, the account tip, and the troubleshooting guide now titled "Paiement effectué, solde non crédité" / "Payment made, balance missing" / "Peman fèt, balans lan pa antre"), on the matching order-issue card and in the agent quick messages ("N'effectuez pas de second paiement"). The website widget's quick-help subtitle reads "Commandes, crédit boutique, PIN et produits" / "Orders, store credit, PINs and products" / "Kòmand, kredi boutik, PIN ak pwodwi".

= 6.6.0 =
* New: Community groups — moderated client-to-client rooms, for signed-in customers only.
* Appears as a "Groupes" tab in the assistant widget and as a standalone page through the [delicat_groups] shortcode.
* Safety, applied before a message is ever stored: MonCash/NatCash and phone numbers masked, PIN and gift-card codes masked, e-mail addresses masked, external links removed unless the store allows them. Player IDs (Free Fire, PUBG) are deliberately left readable — a bare 9 to 12 digit run is not a phone number, and customers trade those all day.
* Impersonation is structurally impossible: the "Équipe" badge is rendered from the WordPress role, never from anything a customer can type, and display names claiming to be Delicat, admin, support or a moderator are refused.
* Abuse controls: per-account rate limiting, per-room slow mode, duplicate rejection, customer reporting with automatic hiding at a configurable threshold, plus mute, ban, delete, pin and room lock for support staff.
* No guest access at any layer — there is no wp_ajax_nopriv_* endpoint in the module.
* New admin screen (Delicat Assistant → Groupes clients): rooms, report queue, safety settings.
* Group history is pruned by the existing daily cleanup job. Pinned messages are never purged.
* Logged-out storefront page views do not load or parse a single byte of the module.

= 6.5.1 =
* Performance fix for slow launching and scroll lag on phones. About 86 KB less is sent or parsed on every storefront page view.
* Fixed a regression introduced in 6.3.0: class-dap-smalltalk.php (63 KB) was added to the storefront kernel, so every page view parsed it even though nothing outside the chat AJAX path ever calls it. It now loads only with the full engine.
* Inline critical CSS cut from 48 KB to 24 KB. 123 blocks were removed, every one of them already present in frontend.css, which the page downloads anyway. The launcher rules stay inline so the orb is still positioned correctly if a page optimiser defers the stylesheet.
* Scroll handlers no longer run per scroll event. viewportMetrics, onPageScroll and updateJump now coalesce into a single animation frame.
* viewportMetrics was writing two CSS custom properties on the html element on every scroll event, which invalidates style for the whole document. It now writes only when a value actually changed. This was the main source of scroll jank on phones, where the handler is active.

= 6.5.0 =
* Automatic top-up is now the assistant's main subject, matching the store: games are credited straight to the game account from the Player ID, in under 60 seconds, with no code involved.
* Fixed the routing bug behind this. Any message containing "free fire" was sent to the PIN card, so the store's best seller - sold as an automatic top-up in five regional builds - was answered with "open your delivered PINs". Game names now reach the top-up card; only gift-card and code language reaches the PIN card.
* New top-up answers: how automatic recharge works, top-up not credited, wrong Player ID entered, and "there is no code for this game". Each names the product asked about.
* Per-game guidance on where the identifier lives: Free Fire (avatar top-left in the lobby), PUBG Mobile (Character ID in the profile), Call of Duty Mobile (UID / Open ID), Roblox (username, not a number), Poppo Live (profile user ID). Games whose layout we cannot state with certainty point to the field on the product page instead of guessing.
* Corpus rebalanced: PIN-related answers dropped from 348 rows to 60, top-up answers went from none to 282. The redemption and PIN-missing templates are now generated only for products that actually deliver a code.
* Six new agent quick messages in a "Recharge auto" group: explain auto top-up, ask for the Player ID, Free Fire region, restart the game, top-up not credited, no code for games.
* Matching fixes from testing real phrasings: "Diaman yo pa antre" (Kreyol spells it diaman as often as dyaman), "How to buy Robux?" and "Kijan pou m achte UC?" were treated as code redemption, "Kote PIN Apple mwen an?" and "Ou trouver mon ID Free Fire ?" hit the redemption steps instead of the right card, and "Mon code Amazon ne marche pas" matched no failure phrase.

= 6.4.1 =
* Product recognition rebuilt against the live catalogue (43 products, verified 7 Sep 2026). The bot recognised 18 of them before; it now recognises all 43, so answers name the actual product instead of falling back to "ce produit".
* Removed products the store does not sell. Spotify, smartwatches, smartphones and laptops were in the product list and the training corpus, so "Livraison pour un laptop ?" produced a confident answer about an item that does not exist. Around 250 corpus rows describing them are gone.
* Delivery and warranty answers corrected: almost everything is digital and arrives in the account in seconds. The Controller X3 is the only physical item, and the answer now points to the "Livraison, retours et garantie" page.
* New Free Fire region answer. Free Fire ships in six builds here (LATAM, BR, EUROPE, ID, MENA, plus FREE FIRE PIN) and nothing handled "which region do I pick?" - the top-up fails if it does not match the account region.
* Kreyol matching fixes found by testing real phrasings: "ranbousman" did not match the refund rule because the word-boundary check required "ranbous" to end the word; "pri" (Kreyol for price) was missing from the price rule; "TransCode la pa mache" matched nothing because "pa mache" was not a recognised failure phrase; "Where do I find my PIN?" had no matching verb; and "M pa resevwa PIN mwen" failed because the rule assumed the noun came before the complaint.
* New PIN-not-received answer card.
* Product aliases are matched on word boundaries throughout. The previous substring shortcut found "wise" inside "otherwise" and "meru" inside "demeure".

= 6.4.0 =
* Agent quick messages: 33 ready-to-send replies in French, English and Kreyol, grouped by moment in the conversation (opening, orders, payment, PIN, delivery, holding, apologies, closing). Previously this feature shipped empty, so the dropdown never rendered and every agent started from a blank page.
* The composer dropdown is replaced by a searchable panel with group tabs. Type "/" in an empty composer to open it, Alt+1..9 to insert without opening it, Enter to insert the top match.
* Placeholders are resolved against the open thread before insertion: {name} {agent} {store} {eta} {wallet} {shop} {account}. An anonymous visitor no longer produces "Bonjour , ici" or "maintenant,." and French spacing before ? and ! is preserved.
* Custom replies in Settings still work and are merged in as their own group. "Label = text" names a reply; a bare line becomes its own label.
* Groups can be switched off per store in Settings.
* The Android admin app receives the same grouped library through the admin API.
* 12 more conversation intents: laughter, "test", impatience, "m okipe", call-back requests, first-time buyers, regular customers, referrals, trust, poor connection, happiness, good wishes. 55 intents and 617 phrasings in total (FR 206 / EN 189 / HT 222).

= 6.3.0 =
* New conversational layer (includes/class-dap-smalltalk.php): 43 intents in French, English and Kreyol, with several wordings each so a repeated message never gets an identical reply.
* A greeting in front of a request no longer loses the greeting. "Bonswa, kote komand mwen an?" is now greeted and answered; previously any support word cancelled small talk entirely.
* Repeated small talk no longer goes unanswered: wordings rotate per conversation and the duplicate-reply window drops from 120s to 8s for social replies.
* The greeting follows the store clock (Bonsoir/Bonswa after 17h) and the reply language follows the message, not the selected tab.
* Name capture: "mwen rele Jean" is remembered for the thread. It does not bypass the agent identification gate, which still requires e-mail or phone.
* Training corpus rebuilt: 1211 unique rows across FR/EN/HT, written against MonCash/NatCash, TransCode, wallet delivery and the electronics range. The previous file held 169 unique questions duplicated 59 times, in English only, describing products the store does not sell.
* Retrieval rewritten around a per-language index (~550 rows) instead of scanning 10000 lines per message. Answers are stored in the language they are served in, so French and Kreyol customers no longer receive an English sentence under a French preamble.
* New "Conversation naturelle" setting, on by default.
* Release notes are no longer shipped inside the plugin; this changelog is the single record.

= 6.2.1 =
* The floating launcher now steps aside while the page scrolls on phones and returns once the page settles, so it never sits on a "Voir tout" link or a product button under the thumb. Works before the runtime has loaded, too.
* On phones the launcher is a 46px orb hugging the bottom dock (8px above it, 10px from the edge); the "Besoin d'aide ?" bubble is desktop-only; collision solving lifts it at most one orb height, then switches side.
* One conversation per signed-in customer across the website and the native app (Delicat App API 2.6.0): the website adopts a thread the app started, and the app creates threads under the website's key.
* Exposes --dap-launcher-clearance on <html> so themes can reserve the space the orb occupies.

= 6.2.0 =
* Fixed the floating launcher opening the assistant when a scroll gesture started on it; opening now requires a real tap.
* Removed the automatic WooCommerce order-status feed from the customer support thread, filtered existing entries and purged them on upgrade. Order status remains available on demand.
* Added a notification sound and haptic tick when the customer receives an agent message, with a mute toggle in the panel header.
* Added an instant e-mail alert on every customer message, sent to the alert address and to every Delicat support agent, with an optional throttle.
* Added a customer directory search in the inbox so the team can send a message to any client, including those who have never opened the chat.
* Added proactive product recommendations when the assistant opens with no product context.
* Fixed cards rendering their title twice when the payload title and body were identical.

= 6.1.0 =
* Added customer draft recovery, sending/delivered/read states, failed-message retry and idempotent message submission.
* Added synchronized website-to-agent typing indicators and bidirectional read receipts.
* Added protected bot handoff context, private internal notes and reward/order/payment information in the support inbox.
* Added unread, urgent and unassigned filters, SLA wait indicators, overdue highlighting and priority-aware ordering.
* Added automatic customer satisfaction requests when a WordPress or Android administrator resolves a conversation.
* Restricted WordPress support agents to unassigned conversations and their own assigned conversations.
* Added authenticated Android API support for internal notes, read receipts, urgent queues and handoff feature discovery.
* Preserved the persistent composer, WooCommerce synchronization, wallet integrations, Firebase push and existing conversation data.

= 6.0.0 =
* Added a persistent customer composer, resilient mobile layouts, synchronized live administrator inbox and secure Android support API.
* Added Firebase push integration, WooCommerce and TeraWallet synchronization, and administrator session management.

= 5.4.0 =
* Rebuilt the mobile conversation toolbar so Back, mode icon, title and status remain aligned on one row.
* Replaced the broken stacked D avatar with a dynamic assistant/team icon.
* Added channel-aware status text and the live agent ETA in the conversation header.
* Added responsive guide/action button layout, stronger mobile spacing and stable 44 px touch targets.
* Added an offline banner and immediate send protection with automatic recovery when the network returns.
* Duplicated the final layout repair in the inline critical CSS so cache optimizers cannot recreate the broken guest layout.
* Preserved WooCommerce, wallet, order, authentication, conversation and mobile-app authority.

= 5.3.1 =
* Fixed the mobile assistant launcher overlapping Delicat Builder V9 bottom navigation.
* Added a mobile-safe dock fallback before the deferred assistant runtime loads.
* Added automatic fixed bottom-bar measurement once the runtime is active.
* Preserved desktop placement and the existing configurable launcher side/bottom offset.
= 5.3.0 =
* Makes Demand loading the default performance mode for new installs and migrates the previous Auto default once during upgrade.
* Keeps only the assistant launcher in the initial storefront HTML; the full panel markup is fetched securely on first interaction.
* Defers unread-count work until browser idle instead of competing with product-page rendering.
* Keeps the existing support runtime, product context, WhatsApp, order support and agent chat behavior after lazy hydration.
* Adds nonce-protected same-origin shell hydration and validates product/page context server-side.
* Preserves all conversations, tickets, settings, devices, sessions and WooCommerce data.

= 5.2.0 =
* Added live product context for WooCommerce product, variation, plan, displayed price and Player ID field state without transmitting Player ID, PIN, OTP or password values.
* Added secure order support shortcuts for the latest order, uncredited payment, missing PIN, Player ID guidance and order-specific agent handoff.
* Added clear bot/agent states, business hours and estimated human response time across website and Android.
* Reworked the help popup: configurable first delay, interval, visible duration and maximum appearances per session; it stops after interaction and stays silent during checkout or typing.
* Added real unread-badge animation and synchronized read state between website and native app.
* Added focus trapping, 44 px touch targets, text scaling and reduced-motion controls.
* Added lazy runtime behavior, Save-Data-aware polling and exponential retry for safe read requests.
* Advanced the Android remote configuration contract to API v2.3 with deep links, token refresh, feature flags, persistent config caching and safe context models.
* Added a read-only Diagnostics center for PHP, WordPress, WooCommerce, database schema, cron, uploads, REST, Firebase and agent alert configuration.
* Added safe page/product context to the private agent inbox so agents understand the customer request without seeing sensitive form values.
* Preserved all conversations, tickets, settings, devices, sessions and WooCommerce data.

= 5.1.1 =
* Added automatic collision avoidance for sticky purchase bars, bottom navigation, WhatsApp buttons and other fixed overlays.
* The launcher now repositions after scrolling, resizing and dynamic WooCommerce/Elementor updates, and can switch sides when the configured corner is crowded.
* Added a lightweight pulse-ring animation around the Delicat bot icon with reduced-motion support.
* Changed the permanent helper label into a timed popup beside the icon.
* “Besoin d’aide ? / Discutez avec nous !” appears after startup, hides after six seconds and returns every 30 seconds.
* Extended the Android v2.2 configuration contract with pulse, popup timing, preferred side, base spacing and collision-avoidance settings.
* Preserved all conversations, tickets, mobile sessions, Firebase devices and existing settings.

= 5.0.3 =
* Removed WooCommerce screen-reader price text that appeared visibly as duplicated “Price range … through …” content.
* Rebuilt the recommendation carousel with fixed media frames, consistent compact card heights and reliable image cropping.
* Increased mobile card width for readable titles and prices while keeping the next item visible as a swipe hint.
* Added strict text clamping, overflow isolation and scroll snapping so products no longer overlap or stretch the assistant.
* Rotated recommendation cache keys so corrected product data appears immediately after upgrading.

= 5.0.2 =
* Rebuilt the mobile panel around the iOS/Android visual viewport so Safari toolbars and the keyboard no longer cover the composer.
* Made the message area the only conversation scroller and removed the flex-end clipping that hid the top of large assistant cards.
* Compacted the mobile header, guide menu, navigation and composer for small screens.
* Added a reversible overlap guard for third-party floating widgets that cover the assistant while it is open.


= 5.0.1 =

* Fixed PHP 8 activation/runtime fatal errors caused by unparenthesized nested ternary expressions.
* Added safe fallbacks for hosts where the optional PHP mbstring extension is unavailable.
* Revalidated every PHP file against PHP 8.4.

= 5.0.0 =

* Rebuilt the customer widget around explicit Assistant, Support team and optional WhatsApp choices.
* Rebuilt the WordPress inbox with search, queues, assignments, priorities, live polling, typing indicators, safe attachments, customer context and responsive layouts.
* Split automated and human messages into indexed database channels; agents no longer receive bot-only conversations.
* Fixed unread counts and read receipts so opening the widget or using the assistant cannot clear unread human replies.
* Added per-message agent identity and preserved the name of the agent who sent each response.
* Added the restricted **Agent support Delicat** role and custom support capability.
* Made tickets enter the waiting queue and removed customer access to internal saved replies.
* Fixed mobile account unlinking so one signed-out installation receives a fresh empty thread without disconnecting other devices or exposing account history.
* Fixed secure mobile token rotation so linked sessions resume the same conversation and stale push registrations cannot remain on the prior account thread.
* Fixed session revocation so its push token is removed too.
* Fixed attachment ownership checks and cleanup after failed writes.
* Added safe schema migration retries, orphan mobile-session cleanup and attachment deletion when a conversation is erased.
* Preserved a guest conversation when the same visitor signs in, and made new or reopened tickets trigger the configured agent alert workflow.
* Reduced public diagnostics to a minimal health response and moved operational screens under Delicat Assistant.
* Preserved webhook secrets when ordinary settings are saved.
* Restored and hardened the frontend runtime, unread badge, polling and native-app reply engine.

== Upgrade Notice ==

= 5.2.0 =

Install this update to enable product-aware assistance, secure order support, smarter help prompts, Android API v2.3 and the diagnostics center. Existing conversations, tickets, settings, devices and mobile sessions are preserved.

= 5.1.1 =

Install this update to stop the chat launcher stacking on product purchase bars or other floating controls, and to enable the pulse animation and 30-second help popup. Existing conversations, tickets, settings and mobile sessions are preserved.

= 5.0.3 =

Install this update to repair malformed recommendation prices and oversized product cards. Existing conversations, settings and product data are preserved.

= 5.0.2 =

Install this mobile layout update to keep the assistant inside the visible Safari/Chrome viewport. Existing conversations and settings are preserved.

= 5.0.1 =

Install this maintenance update before reactivating the plugin on PHP 8.x. Existing settings, conversations and tickets are preserved.

= 5.0.0 =

Back up WordPress before upgrading. Activation adds message-channel columns and the Agent support Delicat role, then migrates existing conversations without deleting them.
