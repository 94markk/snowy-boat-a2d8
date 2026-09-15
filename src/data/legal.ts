/**
 * Legal page copy.
 *
 * A working starting point, not legal advice. Have it reviewed, and replace the
 * placeholders (company registration, refund window) with your real terms
 * before trading.
 */

import type { Locale } from "../i18n/ui";

export interface LegalSection {
  heading: Record<Locale, string>;
  body: Record<Locale, string[]>;
}

export const LEGAL_LAST_UPDATED = "2026-09-15";

export const privacySections: LegalSection[] = [
  {
    heading: { fr: "Les données que nous collectons", en: "What we collect", ht: "Done nou kolekte" },
    body: {
      fr: [
        "Pour créer ton compte nous collectons ton adresse courriel et, si tu le souhaites, ton numéro de téléphone. Pour traiter une commande nous conservons l'identifiant de jeu ou l'adresse de livraison que tu fournis.",
        "Pour créditer ton wallet nous enregistrons le numéro mobile depuis lequel tu envoies le paiement, ainsi que le montant et la référence de la transaction figurant dans le SMS de confirmation de l'opérateur.",
      ],
      en: [
        "To create your account we collect your email address and, if you choose, your phone number. To process an order we keep the game ID or delivery address you give us.",
        "To credit your wallet we record the mobile number you send payment from, along with the amount and transaction reference in the operator's confirmation SMS.",
      ],
      ht: [
        "Pou kreye kont ou, nou pran adrès imèl ou epi, si w vle, nimewo telefòn ou. Pou trete yon kòmand nou kenbe idantifyan jwèt la oswa adrès livrezon w ban nou an.",
        "Pou chaje wallet ou, nou anrejistre nimewo mobil ou voye peman an soti a, ansanm ak montan an ak referans tranzaksyon ki nan SMS konfimasyon operatè a.",
      ],
    },
  },
  {
    heading: { fr: "Mot de passe et sécurité", en: "Passwords and security", ht: "Modpas ak sekirite" },
    body: {
      fr: [
        "Ton mot de passe n'est jamais stocké en clair : nous n'en conservons qu'une empreinte cryptographique, impossible à inverser.",
        "Ton solde est tenu dans un registre où chaque mouvement est inscrit une fois pour toutes. Rien n'est modifié après coup : une correction est une nouvelle ligne.",
      ],
      en: [
        "Your password is never stored in the clear: we keep only a cryptographic hash of it, which cannot be reversed.",
        "Your balance is held in a ledger where every movement is written once. Nothing is edited afterwards — a correction is a new line.",
      ],
      ht: [
        "Nou pa janm kenbe modpas ou an klè : nou kenbe sèlman yon anprent kriptografik ou pa ka retounen.",
        "Balans ou nan yon rejis kote chak mouvman ekri yon sèl fwa. Nou pa modifye anyen apre : yon koreksyon se yon nouvo liy.",
      ],
    },
  },
  {
    heading: { fr: "Cookies", en: "Cookies", ht: "Cookies" },
    body: {
      fr: [
        "Nous utilisons un seul cookie, strictement nécessaire : celui qui te garde connecté. Aucun cookie publicitaire, aucun traceur tiers.",
        "Ton panier et tes favoris restent dans le stockage local de ton navigateur et ne quittent jamais ton appareil tant que tu ne passes pas commande.",
      ],
      en: [
        "We use a single, strictly necessary cookie: the one that keeps you signed in. No advertising cookies, no third-party trackers.",
        "Your cart and favourites stay in your browser's local storage and never leave your device until you place an order.",
      ],
      ht: [
        "Nou sèvi ak yon sèl cookie ki absoliman nesesè : sa ki kenbe w konekte a. Pa gen cookie piblisite, pa gen tras twazyèm pati.",
        "Panye w ak favori w rete nan stokaj lokal navigatè w la epi yo pa janm kite aparèy ou jiskaske w pase yon kòmand.",
      ],
    },
  },
  {
    heading: { fr: "Partage et conservation", en: "Sharing and retention", ht: "Pataj ak konsèvasyon" },
    body: {
      fr: [
        "Nous transmettons à nos fournisseurs uniquement ce qui est nécessaire à la livraison de ton produit : l'identifiant de jeu ou le courriel de livraison, jamais ton mot de passe ni ton historique.",
        "Les données de commande et de paiement sont conservées le temps requis par nos obligations comptables et pour le service après-vente. Nous ne vendons aucune donnée.",
      ],
      en: [
        "We pass our suppliers only what delivery requires — the game ID or the delivery email — never your password or your history.",
        "Order and payment records are kept as long as our accounting obligations and after-sales support require. We do not sell any data.",
      ],
      ht: [
        "Nou voye bay founisè nou yo sèlman sa livrezon an mande — idantifyan jwèt la oswa imèl livrezon an — pa janm modpas ou ni istorik ou.",
        "Nou kenbe dosye kòmand ak peman yo pandan tan obligasyon kontabilite nou ak sèvis apre vant mande. Nou pa vann okenn done.",
      ],
    },
  },
  {
    heading: { fr: "Tes droits", en: "Your rights", ht: "Dwa ou" },
    body: {
      fr: ["Tu peux demander à consulter, corriger ou supprimer les données que nous détenons sur toi. Écris-nous et nous répondrons sous 30 jours."],
      en: ["You can ask to see, correct or delete the data we hold about you. Write to us and we will respond within 30 days."],
      ht: ["Ou ka mande pou w wè, korije oswa efase done nou genyen sou ou. Ekri nou epi n ap reponn nan 30 jou."],
    },
  },
];

export const termsSections: LegalSection[] = [
  {
    heading: { fr: "Produits numériques", en: "Digital products", ht: "Pwodwi dijital" },
    body: {
      fr: [
        "Tous nos produits sont numériques : recharges de jeu, cartes cadeaux, abonnements et services d'échange. Il n'y a ni envoi physique ni frais de port.",
        "Les délais indiqués sur chaque fiche produit sont indicatifs. Les recharges automatiques sont généralement livrées en quelques minutes ; les produits traités par notre équipe peuvent demander plus de temps aux heures de forte affluence.",
      ],
      en: [
        "Everything we sell is digital: game top-ups, gift cards, subscriptions and exchange services. Nothing is shipped and there are no delivery charges.",
        "The times shown on each product page are indicative. Automatic top-ups usually arrive within minutes; products handled by our team can take longer at busy times.",
      ],
      ht: [
        "Tout pwodwi nou yo dijital : rechaj jwèt, kat kado, abònman ak sèvis echanj. Nou pa voye anyen fizikman epi pa gen frè livrezon.",
        "Delè ki make sou chak paj pwodwi se yon endikasyon. Rechaj otomatik yo abitye rive nan kèk minit ; pwodwi ekip nou an okipe yo ka pran plis tan lè gen anpil moun.",
      ],
    },
  },
  {
    heading: { fr: "Prix et paiement", en: "Prices and payment", ht: "Pri ak peman" },
    body: {
      fr: [
        "Les prix sont affichés en gourdes (HTG). Ils peuvent changer à tout moment, mais le prix appliqué est celui affiché au moment où tu valides ta commande.",
        "Les commandes sont réglées avec le solde de ton wallet. Tu alimentes ton wallet par MonCash ou NatCash : envoie le montant exact indiqué, depuis le numéro que tu as déclaré. Ton solde est crédité automatiquement à la réception de la confirmation de l'opérateur.",
        "Un paiement dont le montant ou le numéro d'envoi ne correspond pas à ta demande n'est pas crédité automatiquement. Contacte-nous avec la référence de la transaction et nous le rattachons manuellement.",
      ],
      en: [
        "Prices are shown in gourdes (HTG). They can change at any time, but the price that applies is the one shown when you confirm your order.",
        "Orders are paid from your wallet balance. You fund the wallet with MonCash or NatCash: send the exact amount shown, from the number you declared. Your balance is credited automatically when the operator's confirmation arrives.",
        "A payment whose amount or sending number does not match your request is not credited automatically. Contact us with the transaction reference and we will attach it by hand.",
      ],
      ht: [
        "Pri yo parèt an goud (HTG). Yo ka chanje nenpòt lè, men pri ki aplike a se sa ki te parèt lè w konfime kòmand ou.",
        "Ou peye kòmand yo ak balans wallet ou. Ou chaje wallet la ak MonCash oswa NatCash : voye montan egzak ki make a, soti nan nimewo w te deklare a. Balans ou chaje otomatikman lè konfimasyon operatè a rive.",
        "Yon peman ki gen yon montan oswa yon nimewo ki pa koresponn ak demann ou an p ap chaje otomatikman. Kontakte nou ak referans tranzaksyon an epi n ap mare l alamen.",
      ],
    },
  },
  {
    heading: { fr: "Identifiants de compte", en: "Account IDs", ht: "Idantifyan kont" },
    body: {
      fr: [
        "Pour les recharges de jeu, tu es responsable de l'exactitude de l'identifiant que tu fournis. Une recharge envoyée à un identifiant valide mais erroné ne peut pas être annulée : vérifie-le avant de valider.",
        "Si l'identifiant est invalide, la commande échoue et le montant est automatiquement recrédité sur ton wallet.",
      ],
      en: [
        "For game top-ups you are responsible for the accuracy of the ID you provide. A top-up sent to a valid but wrong ID cannot be reversed — check it before you confirm.",
        "If the ID is invalid the order fails and the amount is automatically credited back to your wallet.",
      ],
      ht: [
        "Pou rechaj jwèt, se ou ki responsab pou idantifyan ou bay la kòrèk. Yon rechaj ki ale sou yon idantifyan ki valab men ki pa bon an pa ka anile — tcheke l anvan w konfime.",
        "Si idantifyan an pa valab, kòmand lan echwe epi montan an retounen otomatikman nan wallet ou.",
      ],
    },
  },
  {
    heading: { fr: "Remboursements", en: "Refunds", ht: "Ranbousman" },
    body: {
      fr: [
        "Un code ou une recharge livré avec succès ne peut pas être remboursé, conformément aux règles de nos fournisseurs.",
        "Si nous ne pouvons pas livrer ta commande, le montant est recrédité sur ton wallet, généralement de façon automatique. Le solde du wallet est utilisable pour toute commande ultérieure.",
      ],
      en: [
        "A code or top-up that has been delivered successfully cannot be refunded, in line with our suppliers' rules.",
        "If we cannot deliver your order, the amount is credited back to your wallet, usually automatically. Wallet balance can be used for any later order.",
      ],
      ht: [
        "Yon kòd oswa yon rechaj ki livre kòrèkteman pa ka ranbouse, dapre règ founisè nou yo.",
        "Si nou pa ka livre kòmand ou, montan an retounen nan wallet ou, anjeneral otomatikman. Ou ka sèvi ak balans wallet la pou nenpòt lòt kòmand.",
      ],
    },
  },
  {
    heading: { fr: "Usage du compte", en: "Account use", ht: "Itilizasyon kont lan" },
    body: {
      fr: [
        "Ton compte est personnel. Tu es responsable de la confidentialité de ton mot de passe et des commandes passées depuis ton compte.",
        "Nous pouvons suspendre un compte en cas de fraude avérée, de paiement contesté ou d'utilisation contraire aux présentes conditions.",
      ],
      en: [
        "Your account is personal. You are responsible for keeping your password confidential and for orders placed from your account.",
        "We may suspend an account in cases of established fraud, a disputed payment, or use that breaches these terms.",
      ],
      ht: [
        "Kont ou se pou ou menm. Se ou ki responsab pou w kenbe modpas ou sekrè epi pou kòmand ki pase sou kont ou.",
        "Nou ka sispann yon kont si gen fwod ki pwouve, yon peman ki konteste, oswa yon itilizasyon ki pa respekte kondisyon sa yo.",
      ],
    },
  },
];
