#!/usr/bin/env bash
# Prépare un dépôt de plugin ou de thème pour la publication automatique. Ne manipule jamais la valeur d'un secret.
# Usage : bash bin/setup-repo.sh wearewp/<slug> <chemin-local-du-plugin-ou-du-thème>
set -euo pipefail

repo="${1:-}"
path="${2:-}"
tools="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! "$repo" =~ ^wearewp/[a-z0-9-]{1,64}$ ]]; then
    echo "Dépôt attendu sous la forme wearewp/<slug> (slug en minuscules)." >&2
    exit 1
fi
if [ -z "$path" ] || [ ! -d "$path" ]; then
    echo "Chemin local du plugin introuvable : $path" >&2
    exit 1
fi
slug="${repo#wearewp/}"

# Thème : style.css avec Theme Name et pas de <slug>.php (même règle que l'ingestion du serveur).
kind=plugin
template="release.yml"
if [ -f "$path/style.css" ] && grep -q 'Theme Name:' "$path/style.css" && [ ! -f "$path/$slug.php" ]; then
    kind=theme
    template="theme-release.yml"
fi
echo "Type détecté : $kind."

visibility="$(gh api "repos/$repo" --jq '.visibility')"
if [ "$visibility" != "private" ]; then
    echo "Le dépôt $repo doit être privé (visibilité actuelle : $visibility)." >&2
    exit 1
fi
repo_id="$(gh api "repos/$repo" --jq '.id')"

for secret in SIGNING_KEY FTP_HOST FTP_USER FTP_PASSWORD; do
    gh api -X PUT "orgs/wearewp/actions/secrets/$secret/repositories/$repo_id" > /dev/null
    echo "Secret $secret accessible à $repo."
done

rulesets="$(gh api "repos/$repo/rulesets" --jq '.[].name')"
if grep -qx 'Tags de version immuables' <<< "$rulesets"; then
    echo "Ruleset déjà présent."
else
    gh api -X POST "repos/$repo/rulesets" --input - > /dev/null <<'JSON'
{
  "name": "Tags de version immuables",
  "target": "tag",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["refs/tags/v*"], "exclude": [] } },
  "rules": [ { "type": "deletion" }, { "type": "update" }, { "type": "non_fast_forward" } ]
}
JSON
    echo "Ruleset « Tags de version immuables » créé."
fi

mkdir -p "$path/.github/workflows"
if [ -f "$path/.github/workflows/release.yml" ]; then
    echo "release.yml déjà présent : non modifié."
else
    cp "$tools/templates/$template" "$path/.github/workflows/release.yml"
    echo "release.yml créé à partir de templates/$template : à commiter."
fi

status=0
if [ ! -f "$path/.distignore" ]; then
    echo "À faire : créer $path/.distignore (fichiers exclus du paquet)." >&2
    status=1
fi
if [ "$kind" = theme ]; then
    if ! grep -qE '^[[:space:]*#@/]*Update URI:[[:space:]]*https://updates\.wearewp\.pro[[:space:]]*$' "$path/style.css" 2>/dev/null; then
        echo "À faire : ajouter l'en-tête « Update URI: https://updates.wearewp.pro » dans style.css." >&2
        status=1
    fi
    if [ ! -f "$path/wawp-theme-updater/load.php" ]; then
        echo "À faire : copier wawp-theme-updater/ dans le thème et ajouter le chargement dans functions.php (voir docs/mise-en-place.md)." >&2
        status=1
    fi
else
    if ! grep -qE '^[[:space:]*#@/]*Update URI:[[:space:]]*https://updates\.wearewp\.pro[[:space:]]*$' "$path/$slug.php" 2>/dev/null; then
        echo "À faire : ajouter l'en-tête « Update URI: https://updates.wearewp.pro » dans $slug.php." >&2
        status=1
    fi
    if [ ! -f "$path/wawp-updater/load.php" ]; then
        echo "À faire : copier wawp-updater/ dans le plugin et ajouter le chargement (voir docs/mise-en-place.md)." >&2
        status=1
    fi
fi
exit "$status"
