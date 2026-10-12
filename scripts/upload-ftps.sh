#!/usr/bin/env bash
# Envoie un paquet et sa signature en FTPS : chaque fichier en .part puis renommé, la signature en dernier.
# Variables : FTP_HOST, FTP_USER, LFTP_PASSWORD. Le mot de passe ne passe jamais sur la ligne de commande.
set -euo pipefail

zip="${1:-}"
sig="$zip.sig"
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

name="$(basename "$zip")"
lftp -c "set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; set net:max-retries 2; set net:timeout 30; \
open --env-password -u \"$FTP_USER\" \"ftp://$FTP_HOST\"; \
put \"$zip\" -o \"$name.part\"; mv \"$name.part\" \"$name\"; \
put \"$sig\" -o \"$name.sig.part\"; mv \"$name.sig.part\" \"$name.sig\""

echo "Envoyé : $name et $name.sig"
