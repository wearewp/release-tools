#!/usr/bin/env bash
# Envoie un paquet et sa signature en FTPS : chaque fichier en .part puis renommé, la signature en dernier.
# Variables : FTP_HOST, FTP_USER, LFTP_PASSWORD. Le mot de passe ne passe jamais sur la ligne de commande.
set -euo pipefail

zip="${1:-}"
sig="$zip.sig"
name="$(basename -- "${zip:-invalide}")"

# Nom contrôlé avant tout appel à lftp : il est interpolé dans la commande lftp.
pattern='^[a-z0-9-]{1,64}-[0-9]{1,5}(\.[0-9]{1,5}){2}\.zip$'
if [[ ! "$name" =~ $pattern ]]; then
    echo "Nom de paquet invalide : $name (attendu <slug>-X.Y.Z.zip)" >&2
    exit 1
fi
for variable in FTP_HOST FTP_USER LFTP_PASSWORD; do
    if [ -z "${!variable:-}" ]; then
        echo "Variable manquante : $variable" >&2
        exit 1
    fi
done
if [ ! -f "$zip" ] || [ ! -f "$sig" ]; then
    echo "Paquet ou signature absent : $zip" >&2
    exit 1
fi

# Liste de confiance : magasin du système, plus les intermédiaires que le serveur
# FTP n'envoie pas (config/ftps-intermediates.pem). La vérification reste active.
system_ca=/etc/ssl/certs/ca-certificates.crt
intermediates="$(dirname -- "${BASH_SOURCE[0]}")/../config/ftps-intermediates.pem"
if [ ! -f "$system_ca" ] || [ ! -f "$intermediates" ]; then
    echo "Certificats de confiance absents : $system_ca ou $intermediates" >&2
    exit 1
fi
ca_file="$(mktemp)"
trap 'rm -f -- "$ca_file"' EXIT
cat -- "$system_ca" "$intermediates" > "$ca_file"

lftp -c "set cmd:fail-exit true; set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; set ssl:ca-file \"$ca_file\"; set net:max-retries 2; set net:timeout 30; \
open --env-password -u \"$FTP_USER\" \"ftp://$FTP_HOST\"; \
put \"$zip\" -o \"$name.part\"; mv \"$name.part\" \"$name\"; \
put \"$sig\" -o \"$name.sig.part\"; mv \"$name.sig.part\" \"$name.sig\""

echo "Envoyé : $name et $name.sig"
