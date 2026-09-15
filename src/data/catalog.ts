/**
 * The product catalog.
 *
 * Single source of truth for prices. Nothing the browser sends is ever trusted:
 * order totals are recomputed from the variants below, server-side, every time.
 *
 * Products are digital, so there is no shipping and no stock in the physical
 * sense. What varies is the *denomination* (a variant) and how it is fulfilled.
 */

import type { Locale } from "../i18n/ui";

export type CategoryId = "jeux" | "gift-card" | "abonnement" | "echanges";

/** How a purchased variant is turned into something the customer receives. */
export type FulfilmentKind =
  /** Server calls the distributor API and gets a code or a confirmed top-up. */
  | "supplier_api"
  /** Staff completes it by hand in the admin panel. */
  | "manual";

export interface Category {
  id: CategoryId;
  slug: string;
  name: Record<Locale, string>;
  blurb: Record<Locale, string>;
  /** Inline SVG path data for the category icon. */
  icon: string;
}

/**
 * A single buyable denomination — "310 diamants", "Netflix 1 mois", "25 USD".
 */
export interface Variant {
  /** Globally unique. Sent to the supplier and stored on the order line. */
  id: string;
  label: Record<Locale, string>;
  /** Integer centimes of gourde. */
  priceCentimes: number;
  /** Optional strike-through reference price, in centimes. */
  compareAtCentimes?: number;
  /** Identifier this denomination has in the distributor's catalog. */
  supplierSku?: string;
  available: boolean;
}

export interface Product {
  id: string;
  slug: string;
  category: CategoryId;
  /** Brand names are not translated. */
  name: string;
  /** Disambiguates regional editions, e.g. "LATAM", "Global", "US". */
  region?: string;
  short: Record<Locale, string>;
  description: Record<Locale, string>;
  image: string;
  variants: Variant[];
  fulfilment: FulfilmentKind;
  /** Which distributor fulfils this product. See src/lib/suppliers/. */
  supplier?: string;
  /**
   * Extra input the customer must provide for the top-up to be applied —
   * a game account id, an email, a phone number. Validated server-side.
   */
  requiredFields?: RequiredField[];
  badges?: ("top-vente" | "promo" | "nouveau")[];
  /** Position in the "Les plus achetés" ranking; lower is higher. */
  rank?: number;
  featured?: boolean;
}

export interface RequiredField {
  name: string;
  label: Record<Locale, string>;
  /** Hint shown under the input, e.g. where to find the id in the game. */
  help?: Record<Locale, string>;
  /** Anchored server-side against the whole value. */
  pattern: string;
  maxLength: number;
  inputMode?: "numeric" | "email" | "tel" | "text";
}

/* -------------------------------------------------------------------------- */
/* Reusable field definitions                                                  */
/* -------------------------------------------------------------------------- */

const playerIdField = (
  help: Record<Locale, string>,
  { maxLength = 20 }: { maxLength?: number } = {},
): RequiredField => ({
  name: "player_id",
  label: {
    fr: "Identifiant du compte",
    en: "Account ID",
    ht: "Idantifyan kont lan",
  },
  help,
  pattern: "^[0-9]{5,20}$",
  maxLength,
  inputMode: "numeric",
});

const emailField: RequiredField = {
  name: "delivery_email",
  label: {
    fr: "Courriel de livraison",
    en: "Delivery email",
    ht: "Imèl pou livrezon",
  },
  help: {
    fr: "Le code sera envoyé à cette adresse.",
    en: "The code will be sent to this address.",
    ht: "N ap voye kòd la nan adrès sa a.",
  },
  pattern: "^[^@\\s]{1,64}@[^@\\s.]+(\\.[^@\\s.]+)+$",
  maxLength: 120,
  inputMode: "email",
};

/* -------------------------------------------------------------------------- */
/* Categories                                                                  */
/* -------------------------------------------------------------------------- */

export const categories: Category[] = [
  {
    id: "jeux",
    slug: "jeux",
    name: { fr: "Jeux", en: "Games", ht: "Jwèt" },
    blurb: {
      fr: "Recharge automatique sur votre compte.",
      en: "Topped up straight to your account.",
      ht: "Rechaj otomatik sou kont ou.",
    },
    icon: "M7 8h10a4 4 0 0 1 3.9 3.1l1 4.3A2.6 2.6 0 0 1 19.4 18.6a2.6 2.6 0 0 1-2.2-1.2L16 15.6H8l-1.2 1.8a2.6 2.6 0 0 1-2.2 1.2 2.6 2.6 0 0 1-2.5-3.2l1-4.3A4 4 0 0 1 7 8Zm-.5 3v3M5 12.5h3m7.5-.5h.01M18 14h.01",
  },
  {
    id: "gift-card",
    slug: "gift-card",
    name: { fr: "Gift Card", en: "Gift cards", ht: "Kat Kado" },
    blurb: {
      fr: "Cartes cadeaux livrées par code.",
      en: "Gift cards delivered as a code.",
      ht: "Kat kado yo livre sou fòm kòd.",
    },
    icon: "M4 11h16v8.5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19.5V11Zm-.5-4h17a.5.5 0 0 1 .5.5V11H3V7.5a.5.5 0 0 1 .5-.5ZM12 7v14M12 7S10.5 3 8.4 3a2 2 0 0 0 0 4H12Zm0 0s1.5-4 3.6-4a2 2 0 0 1 0 4H12Z",
  },
  {
    id: "abonnement",
    slug: "abonnement",
    name: { fr: "Abonnement Premium", en: "Premium subscriptions", ht: "Abònman Premium" },
    blurb: {
      fr: "Vos plateformes préférées, à portée de main.",
      en: "Your favourite platforms, within reach.",
      ht: "Platfòm ou pi renmen yo, anba men w.",
    },
    icon: "m12 3.2 2.6 5.6 6 .7-4.5 4.2 1.3 6-5.4-3.1-5.4 3.1 1.3-6L3.4 9.5l6-.7L12 3.2Z",
  },
  {
    id: "echanges",
    slug: "echanges",
    name: { fr: "Échanges", en: "Exchange", ht: "Echanj" },
    blurb: {
      fr: "Vos services financiers essentiels.",
      en: "Your essential financial services.",
      ht: "Sèvis finansye enpòtan yo.",
    },
    icon: "M4 8h13l-2.5-2.5M20 16H7l2.5 2.5",
  },
];

/* -------------------------------------------------------------------------- */
/* Products                                                                    */
/* -------------------------------------------------------------------------- */

export const products: Product[] = [
  {
    id: "free-fire-latam",
    slug: "free-fire-latam",
    category: "jeux",
    name: "Free Fire",
    region: "LATAM",
    short: {
      fr: "Diamants crédités directement sur votre compte.",
      en: "Diamonds credited straight to your account.",
      ht: "Dyaman ki ale dirèkteman sou kont ou.",
    },
    description: {
      fr: "Rechargez vos diamants Free Fire en quelques secondes. Entrez votre identifiant de joueur, choisissez votre pack et recevez vos diamants sans passer par le magasin du jeu.",
      en: "Top up your Free Fire diamonds in seconds. Enter your player ID, pick a pack, and the diamonds land without going through the in-game store.",
      ht: "Chaje dyaman Free Fire ou nan kèk segonn. Mete idantifyan jwè w la, chwazi pak ou epi dyaman yo rive san w pa pase nan boutik jwèt la.",
    },
    image: "/images/products/free-fire-latam.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    badges: ["top-vente"],
    rank: 1,
    featured: true,
    requiredFields: [
      playerIdField({
        fr: "Votre ID se trouve sur votre profil, sous votre avatar.",
        en: "Your ID is on your profile, under your avatar.",
        ht: "ID ou parèt sou pwofil ou, anba foto w.",
      }),
    ],
    variants: [
      { id: "ff-latam-100", label: { fr: "100 diamants", en: "100 diamonds", ht: "100 dyaman" }, priceCentimes: 145_00, supplierSku: "FF_LATAM_100", available: true },
      { id: "ff-latam-310", label: { fr: "310 diamants", en: "310 diamonds", ht: "310 dyaman" }, priceCentimes: 430_00, supplierSku: "FF_LATAM_310", available: true },
      { id: "ff-latam-520", label: { fr: "520 diamants", en: "520 diamonds", ht: "520 dyaman" }, priceCentimes: 700_00, supplierSku: "FF_LATAM_520", available: true },
      { id: "ff-latam-1060", label: { fr: "1 060 diamants", en: "1,060 diamonds", ht: "1 060 dyaman" }, priceCentimes: 1_390_00, supplierSku: "FF_LATAM_1060", available: true },
      { id: "ff-latam-2180", label: { fr: "2 180 diamants", en: "2,180 diamonds", ht: "2 180 dyaman" }, priceCentimes: 2_750_00, supplierSku: "FF_LATAM_2180", available: true },
    ],
  },
  {
    id: "free-fire-pin",
    slug: "free-fire-pin",
    category: "jeux",
    name: "Free Fire PIN",
    short: {
      fr: "Codes PIN à saisir vous-même dans le jeu.",
      en: "PIN codes you redeem yourself in-game.",
      ht: "Kòd PIN pou w mete tèt ou nan jwèt la.",
    },
    description: {
      fr: "Recevez un code PIN Garena à utiliser sur le centre de recharge officiel. Pratique si vous préférez ne pas partager votre identifiant.",
      en: "Get a Garena PIN code to use on the official top-up centre. Handy if you would rather not share your player ID.",
      ht: "Resevwa yon kòd PIN Garena pou w itilize sou sant rechaj ofisyèl la. Li pratik si w prefere pa bay idantifyan w.",
    },
    image: "/images/products/free-fire-pin.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    badges: ["promo"],
    rank: 2,
    featured: true,
    requiredFields: [emailField],
    variants: [
      { id: "ffpin-110", label: { fr: "110 diamants", en: "110 diamonds", ht: "110 dyaman" }, priceCentimes: 148_00, compareAtCentimes: 175_00, supplierSku: "FFPIN_110", available: true },
      { id: "ffpin-341", label: { fr: "341 diamants", en: "341 diamonds", ht: "341 dyaman" }, priceCentimes: 445_00, supplierSku: "FFPIN_341", available: true },
      { id: "ffpin-572", label: { fr: "572 diamants", en: "572 diamonds", ht: "572 dyaman" }, priceCentimes: 720_00, supplierSku: "FFPIN_572", available: true },
    ],
  },
  {
    id: "pubg-mobile",
    slug: "pubg-mobile",
    category: "jeux",
    name: "PUBG Mobile",
    short: {
      fr: "UC crédités sur votre compte PUBG.",
      en: "UC credited to your PUBG account.",
      ht: "UC ki ale sou kont PUBG ou.",
    },
    description: {
      fr: "Rechargez vos UC PUBG Mobile avec votre identifiant de joueur. Livraison automatique après confirmation du paiement.",
      en: "Top up your PUBG Mobile UC with your player ID. Delivered automatically once payment is confirmed.",
      ht: "Chaje UC PUBG Mobile ou ak idantifyan jwè w. Livrezon otomatik yon fwa peman an konfime.",
    },
    image: "/images/products/pubg-mobile.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    badges: ["top-vente"],
    rank: 3,
    featured: true,
    requiredFields: [
      playerIdField({
        fr: "Ouvrez votre profil dans le jeu : l'ID est sous votre pseudo.",
        en: "Open your in-game profile: the ID sits under your nickname.",
        ht: "Louvri pwofil ou nan jwèt la : ID a anba non w.",
      }),
    ],
    variants: [
      { id: "pubg-60", label: { fr: "60 UC", en: "60 UC", ht: "60 UC" }, priceCentimes: 170_00, supplierSku: "PUBG_60", available: true },
      { id: "pubg-325", label: { fr: "325 UC", en: "325 UC", ht: "325 UC" }, priceCentimes: 810_00, supplierSku: "PUBG_325", available: true },
      { id: "pubg-660", label: { fr: "660 UC", en: "660 UC", ht: "660 UC" }, priceCentimes: 1_590_00, supplierSku: "PUBG_660", available: true },
      { id: "pubg-1800", label: { fr: "1 800 UC", en: "1,800 UC", ht: "1 800 UC" }, priceCentimes: 4_150_00, supplierSku: "PUBG_1800", available: true },
    ],
  },
  {
    id: "blood-strike",
    slug: "blood-strike",
    category: "jeux",
    name: "Blood Strike",
    short: {
      fr: "Gold crédité sur votre compte.",
      en: "Gold credited to your account.",
      ht: "Gold ki ale sou kont ou.",
    },
    description: {
      fr: "Rechargez votre Gold Blood Strike en indiquant votre identifiant de joueur.",
      en: "Top up your Blood Strike Gold by giving your player ID.",
      ht: "Chaje Gold Blood Strike ou lè w bay idantifyan jwè w.",
    },
    image: "/images/products/blood-strike.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    badges: ["top-vente"],
    requiredFields: [
      playerIdField({
        fr: "L'ID apparaît dans les paramètres du compte.",
        en: "The ID appears in your account settings.",
        ht: "ID a parèt nan paramèt kont lan.",
      }),
    ],
    variants: [
      { id: "bs-50", label: { fr: "50 Gold", en: "50 Gold", ht: "50 Gold" }, priceCentimes: 80_00, supplierSku: "BS_50", available: true },
      { id: "bs-300", label: { fr: "300 Gold", en: "300 Gold", ht: "300 Gold" }, priceCentimes: 430_00, supplierSku: "BS_300", available: true },
      { id: "bs-1000", label: { fr: "1 000 Gold", en: "1,000 Gold", ht: "1,000 Gold" }, priceCentimes: 1_350_00, supplierSku: "BS_1000", available: true },
    ],
  },
  {
    id: "dls-2026",
    slug: "dls-2026",
    category: "jeux",
    name: "Dream League Soccer 2026",
    short: {
      fr: "Pièces et gemmes DLS 2026.",
      en: "DLS 2026 coins and gems.",
      ht: "Pyès ak jèm DLS 2026.",
    },
    description: {
      fr: "Rechargez vos pièces Dream League Soccer 2026 directement sur votre compte de jeu.",
      en: "Top up your Dream League Soccer 2026 coins straight to your game account.",
      ht: "Chaje pyès Dream League Soccer 2026 ou dirèkteman sou kont jwèt ou.",
    },
    image: "/images/products/dls-2026.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    requiredFields: [
      playerIdField({
        fr: "Votre ID DLS se trouve dans le menu Profil.",
        en: "Your DLS ID is in the Profile menu.",
        ht: "ID DLS ou nan meni Pwofil la.",
      }),
    ],
    variants: [
      { id: "dls-500", label: { fr: "500 pièces", en: "500 coins", ht: "500 pyès" }, priceCentimes: 500_00, supplierSku: "DLS_500", available: true },
      { id: "dls-1200", label: { fr: "1 200 pièces", en: "1,200 coins", ht: "1 200 pyès" }, priceCentimes: 1_100_00, supplierSku: "DLS_1200", available: true },
    ],
  },

  {
    id: "apple-gift-card",
    slug: "apple-gift-card",
    category: "gift-card",
    name: "Apple Gift Card",
    region: "US",
    short: {
      fr: "Pour l'App Store, iTunes et iCloud.",
      en: "For the App Store, iTunes and iCloud.",
      ht: "Pou App Store, iTunes ak iCloud.",
    },
    description: {
      fr: "Carte cadeau Apple valable sur le store américain. Le code vous est envoyé dès la confirmation du paiement.",
      en: "Apple gift card valid on the US store. The code is sent as soon as payment is confirmed.",
      ht: "Kat kado Apple ki bon sou store ameriken an. N ap voye kòd la yon fwa peman an konfime.",
    },
    image: "/images/products/apple-gift-card.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    badges: ["top-vente"],
    featured: true,
    requiredFields: [emailField],
    variants: [
      { id: "apple-2", label: { fr: "2 USD", en: "2 USD", ht: "2 USD" }, priceCentimes: 320_00, supplierSku: "APPLE_US_2", available: true },
      { id: "apple-5", label: { fr: "5 USD", en: "5 USD", ht: "5 USD" }, priceCentimes: 780_00, supplierSku: "APPLE_US_5", available: true },
      { id: "apple-10", label: { fr: "10 USD", en: "10 USD", ht: "10 USD" }, priceCentimes: 1_530_00, supplierSku: "APPLE_US_10", available: true },
      { id: "apple-25", label: { fr: "25 USD", en: "25 USD", ht: "25 USD" }, priceCentimes: 3_750_00, supplierSku: "APPLE_US_25", available: true },
    ],
  },
  {
    id: "roblox",
    slug: "roblox",
    category: "gift-card",
    name: "Roblox",
    region: "Global",
    short: {
      fr: "Robux par code cadeau.",
      en: "Robux by gift code.",
      ht: "Robux ak kòd kado.",
    },
    description: {
      fr: "Carte cadeau Roblox utilisable partout dans le monde. Échangez le code sur roblox.com/redeem pour créditer vos Robux.",
      en: "Roblox gift card usable worldwide. Redeem the code at roblox.com/redeem to credit your Robux.",
      ht: "Kat kado Roblox ou ka itilize toupatou. Echanje kòd la sou roblox.com/redeem pou w jwenn Robux ou.",
    },
    image: "/images/products/roblox.svg",
    fulfilment: "supplier_api",
    supplier: "stub",
    featured: true,
    requiredFields: [emailField],
    variants: [
      { id: "roblox-5", label: { fr: "5 USD · 400 Robux", en: "5 USD · 400 Robux", ht: "5 USD · 400 Robux" }, priceCentimes: 650_00, supplierSku: "RBLX_5", available: true },
      { id: "roblox-10", label: { fr: "10 USD · 800 Robux", en: "10 USD · 800 Robux", ht: "10 USD · 800 Robux" }, priceCentimes: 1_280_00, supplierSku: "RBLX_10", available: true },
      { id: "roblox-25", label: { fr: "25 USD · 2 200 Robux", en: "25 USD · 2,200 Robux", ht: "25 USD · 2 200 Robux" }, priceCentimes: 3_150_00, supplierSku: "RBLX_25", available: true },
    ],
  },

  {
    id: "netflix",
    slug: "netflix",
    category: "abonnement",
    name: "Netflix",
    short: {
      fr: "Abonnement prêt à l'emploi.",
      en: "Ready-to-use subscription.",
      ht: "Abònman ki pare pou itilize.",
    },
    description: {
      fr: "Accédez à Netflix sans carte bancaire internationale. Choisissez la durée, nous activons l'abonnement et vous transmettons les accès.",
      en: "Get Netflix without an international card. Pick a duration, we activate the subscription and send you the access details.",
      ht: "Jwenn Netflix san kat bankè entènasyonal. Chwazi konbyen tan, nou aktive abònman an epi nou voye aksè yo ba ou.",
    },
    image: "/images/products/netflix.svg",
    fulfilment: "manual",
    badges: ["top-vente"],
    featured: true,
    requiredFields: [emailField],
    variants: [
      { id: "netflix-1m", label: { fr: "1 mois", en: "1 month", ht: "1 mwa" }, priceCentimes: 450_00, available: true },
      { id: "netflix-3m", label: { fr: "3 mois", en: "3 months", ht: "3 mwa" }, priceCentimes: 1_290_00, available: true },
      { id: "netflix-6m", label: { fr: "6 mois", en: "6 months", ht: "6 mwa" }, priceCentimes: 2_450_00, available: true },
    ],
  },
  {
    id: "crunchyroll",
    slug: "crunchyroll",
    category: "abonnement",
    name: "Crunchyroll",
    short: {
      fr: "Anime en illimité.",
      en: "Unlimited anime.",
      ht: "Anime san limit.",
    },
    description: {
      fr: "Abonnement Crunchyroll Premium activé sur votre compte ou livré avec des accès dédiés.",
      en: "Crunchyroll Premium activated on your account, or delivered with dedicated access.",
      ht: "Abònman Crunchyroll Premium yo aktive sou kont ou oswa yo livre l ak aksè dedye.",
    },
    image: "/images/products/crunchyroll.svg",
    fulfilment: "manual",
    requiredFields: [emailField],
    variants: [
      { id: "crunchy-1m", label: { fr: "1 mois", en: "1 month", ht: "1 mwa" }, priceCentimes: 600_00, available: true },
      { id: "crunchy-3m", label: { fr: "3 mois", en: "3 months", ht: "3 mwa" }, priceCentimes: 1_650_00, available: true },
    ],
  },

  {
    id: "paypal",
    slug: "paypal",
    category: "echanges",
    name: "PayPal",
    short: {
      fr: "Rechargez votre solde PayPal.",
      en: "Top up your PayPal balance.",
      ht: "Chaje balans PayPal ou.",
    },
    description: {
      fr: "Créditez votre compte PayPal en gourdes. Indiquez le courriel de votre compte PayPal et le montant souhaité.",
      en: "Credit your PayPal account in gourdes. Give your PayPal account email and the amount you want.",
      ht: "Chaje kont PayPal ou an goud. Bay imèl kont PayPal ou ak konbyen ou vle.",
    },
    image: "/images/products/paypal.svg",
    fulfilment: "manual",
    featured: true,
    requiredFields: [
      {
        ...emailField,
        name: "paypal_email",
        label: { fr: "Courriel PayPal", en: "PayPal email", ht: "Imèl PayPal" },
        help: {
          fr: "Le compte PayPal qui recevra le montant.",
          en: "The PayPal account that will receive the amount.",
          ht: "Kont PayPal ki pral resevwa lajan an.",
        },
      },
    ],
    variants: [
      { id: "paypal-5", label: { fr: "5 USD", en: "5 USD", ht: "5 USD" }, priceCentimes: 145_00 * 5, available: true },
      { id: "paypal-10", label: { fr: "10 USD", en: "10 USD", ht: "10 USD" }, priceCentimes: 145_00 * 10, available: true },
      { id: "paypal-25", label: { fr: "25 USD", en: "25 USD", ht: "25 USD" }, priceCentimes: 145_00 * 25, available: true },
    ],
  },
  {
    id: "meru",
    slug: "meru",
    category: "echanges",
    name: "Meru",
    short: {
      fr: "Rechargez votre compte Meru.",
      en: "Top up your Meru account.",
      ht: "Chaje kont Meru ou.",
    },
    description: {
      fr: "Alimentez votre compte Meru en quelques minutes, sans passer par une banque.",
      en: "Fund your Meru account in minutes, without going through a bank.",
      ht: "Mete lajan sou kont Meru ou nan kèk minit, san w pa pase nan bank.",
    },
    image: "/images/products/meru.svg",
    fulfilment: "manual",
    badges: ["top-vente"],
    featured: true,
    requiredFields: [
      {
        ...emailField,
        name: "meru_account",
        label: { fr: "Compte Meru", en: "Meru account", ht: "Kont Meru" },
        help: {
          fr: "Le courriel associé à votre compte Meru.",
          en: "The email tied to your Meru account.",
          ht: "Imèl ki mare ak kont Meru ou.",
        },
      },
    ],
    variants: [
      { id: "meru-1", label: { fr: "1 USD", en: "1 USD", ht: "1 USD" }, priceCentimes: 144_00, available: true },
      { id: "meru-5", label: { fr: "5 USD", en: "5 USD", ht: "5 USD" }, priceCentimes: 144_00 * 5, available: true },
      { id: "meru-20", label: { fr: "20 USD", en: "20 USD", ht: "20 USD" }, priceCentimes: 144_00 * 20, available: true },
    ],
  },
];

/* -------------------------------------------------------------------------- */
/* Lookups                                                                     */
/* -------------------------------------------------------------------------- */

const productById = new Map(products.map((p) => [p.id, p]));
const variantIndex = new Map<string, { product: Product; variant: Variant }>();

for (const product of products) {
  for (const variant of product.variants) {
    if (variantIndex.has(variant.id)) {
      throw new Error(`Duplicate variant id in catalog: ${variant.id}`);
    }
    variantIndex.set(variant.id, { product, variant });
  }
}

export function getProduct(id: string): Product | undefined {
  return productById.get(id);
}

export function getProductBySlug(slug: string): Product | undefined {
  return products.find((p) => p.slug === slug);
}

/** Resolve a variant id to its product and denomination. The checkout path
 *  depends on this: an id that is not here cannot be bought. */
export function getVariant(id: string): { product: Product; variant: Variant } | undefined {
  return variantIndex.get(id);
}

export function getCategory(id: CategoryId): Category | undefined {
  return categories.find((c) => c.id === id);
}

export function getCategoryBySlug(slug: string): Category | undefined {
  return categories.find((c) => c.slug === slug);
}

export function productsInCategory(id: CategoryId): Product[] {
  return products.filter((p) => p.category === id);
}

export function countInCategory(id: CategoryId): number {
  return productsInCategory(id).length;
}

/** Cheapest available denomination — the "À partir de G144" figure. */
export function startingPrice(product: Product): number {
  const available = product.variants.filter((v) => v.available);
  const pool = available.length > 0 ? available : product.variants;
  return Math.min(...pool.map((v) => v.priceCentimes));
}

export function featuredProducts(limit = 8): Product[] {
  return products.filter((p) => p.featured).slice(0, limit);
}

/** The "Les plus achetés" list, ordered by the editorial rank. */
export function rankedProducts(limit = 6): Product[] {
  return products
    .filter((p) => p.rank !== undefined)
    .sort((a, b) => a.rank! - b.rank!)
    .slice(0, limit);
}

/** Naive but adequate search over name, region and short description. */
export function searchProducts(query: string, locale: Locale): Product[] {
  const needle = query.trim().toLowerCase();
  if (needle.length < 2) return [];
  return products.filter((p) =>
    [p.name, p.region ?? "", p.short[locale]].join(" ").toLowerCase().includes(needle),
  );
}
