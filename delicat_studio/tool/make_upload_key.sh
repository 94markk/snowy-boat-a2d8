#!/usr/bin/env bash
# Crée la clé d'importation Google Play (upload key) de Delicat Studio, HORS du dépôt,
# puis écrit android/key.properties qui pointe vers elle.
#
# Usage : tool/make_upload_key.sh [chemin-du-keystore] [alias]
#   chemin-du-keystore  par défaut : $HOME/delicat-upload.jks
#   alias               par défaut : upload
#
# Les mots de passe sont demandés au clavier, jamais en argument, jamais affichés.
# Le keystore est au format PKCS12 : la clé et le keystore partagent le même mot de passe.
set -euo pipefail

die() { printf 'Erreur : %s\n' "$*" >&2; exit 1; }

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
app_dir="$(dirname "$script_dir")"
props="$app_dir/android/key.properties"
repo_root="$(git -C "$app_dir" rev-parse --show-toplevel 2>/dev/null || printf '%s' "$app_dir")"
repo_root="$(cd "$repo_root" && pwd -P)"

keystore="${1:-$HOME/delicat-upload.jks}"
key_alias="${2:-upload}"
dname="CN=Delicat Studio, O=Delicat Store Haiti, C=HT"

command -v keytool >/dev/null 2>&1 || die "keytool introuvable. Installe un JDK 17 ou plus récent (ou utilise celui d'Android Studio) et ajoute-le au PATH."
[[ -t 0 ]] || die "ce script est interactif : lance-le dans un terminal."
[[ "$key_alias" =~ ^[A-Za-z0-9._-]+$ ]] || die "alias invalide (lettres, chiffres, . _ - uniquement)."

# Chemin absolu du keystore, et refus s'il est dans le dépôt.
ks_dir="$(dirname "$keystore")"
[[ -d "$ks_dir" ]] || die "le dossier $ks_dir n'existe pas."
keystore="$(cd "$ks_dir" && pwd -P)/$(basename "$keystore")"
case "$keystore" in
  "$repo_root"/*) die "le keystore ne doit pas être dans le dépôt ($repo_root). Choisis un chemin en dehors, par exemple \$HOME/delicat-upload.jks." ;;
esac
[[ -e "$keystore" ]] && die "$keystore existe déjà. Je ne l'écrase pas : une clé perdue ne se récupère pas."

if [[ -e "$props" ]]; then
  printf '%s existe déjà et pointe peut-être vers ta vraie clé.\n' "$props"
  read -r -p "Le remplacer ? [o/N] " answer
  [[ "$answer" =~ ^[oOyY]$ ]] || die "annulé, rien n'a été modifié."
fi

# Caractères ASCII imprimables uniquement : key.properties est lu en ISO-8859-1 par Gradle.
is_ascii_printable() {
  local LC_ALL=C
  [[ "$1" != *[![:print:]]* ]]
}

read_password() {
  local first second
  while true; do
    read -r -s -p "Mot de passe du keystore (6 caractères minimum) : " first; printf '\n' >&2
    read -r -s -p "Confirme le mot de passe : " second; printf '\n' >&2
    if [[ "$first" != "$second" ]]; then
      printf 'Les deux saisies sont différentes, recommence.\n' >&2
    elif (( ${#first} < 6 )); then
      printf 'Trop court : 6 caractères minimum.\n' >&2
    elif ! is_ascii_printable "$first"; then
      printf 'Utilise uniquement des lettres sans accents, des chiffres et des symboles du clavier.\n' >&2
    else
      REPLY="$first"
      return 0
    fi
  done
}

# Échappe une valeur pour un fichier .properties.
props_escape() {
  local s="${1//\\/\\\\}"
  [[ "$s" == [[:space:]]* ]] && s="\\$s"
  printf '%s' "$s"
}

printf 'Keystore : %s\nAlias    : %s\n\n' "$keystore" "$key_alias"
read_password
DELICAT_UPLOAD_PASS="$REPLY"
unset REPLY
export DELICAT_UPLOAD_PASS
trap 'unset DELICAT_UPLOAD_PASS' EXIT

umask 077

# -storepass:env / -keypass:env : le mot de passe ne passe pas dans la ligne de commande.
keytool -genkeypair -v \
  -keystore "$keystore" -storetype PKCS12 \
  -alias "$key_alias" -keyalg RSA -keysize 2048 -validity 10000 \
  -dname "$dname" \
  -storepass:env DELICAT_UPLOAD_PASS -keypass:env DELICAT_UPLOAD_PASS

pass_escaped="$(props_escape "$DELICAT_UPLOAD_PASS")"
{
  printf '# Généré par tool/make_upload_key.sh le %s. Ne jamais committer ce fichier.\n' "$(date '+%Y-%m-%d')"
  printf 'storePassword=%s\n' "$pass_escaped"
  printf 'keyPassword=%s\n' "$pass_escaped"
  printf 'keyAlias=%s\n' "$key_alias"
  printf 'storeFile=%s\n' "$(props_escape "$keystore")"
} > "$props"
unset pass_escaped

fingerprint="$(keytool -list -v -keystore "$keystore" -alias "$key_alias" -storepass:env DELICAT_UPLOAD_PASS 2>/dev/null \
  | grep -E 'SHA-?256' | head -n 1 | sed -E 's/^[[:space:]]+//' || true)"

cat <<EOF

C'est prêt.
  Keystore       : $keystore
  key.properties : $props
${fingerprint:+  Empreinte      : $fingerprint
}
À faire maintenant :
  1. Sauvegarde le keystore ET son mot de passe à deux endroits sûrs
     (gestionnaire de mots de passe + copie hors ligne). Jamais dans le dépôt.
  2. Si tu perds le keystore ou le mot de passe, tu ne pourras plus publier de mise à jour
     avec cette clé : il faudra demander une réinitialisation de la clé d'importation
     dans la Play Console (Intégrité de l'application > Signature d'application).
  3. Vérifie que git ignore bien la clé : git status ne doit pas lister key.properties.

Build signé : flutter build appbundle --release
EOF
