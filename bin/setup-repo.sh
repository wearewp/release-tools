#!/usr/bin/env bash
# Prépare un dépôt de plugin pour la publication automatique. Ne manipule jamais la valeur d'un secret.
# Usage : bash bin/setup-repo.sh wearewp/<slug> <chemin-local-du-plugin>
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
    cp "$tools/templates/release.yml" "$path/.github/workflows/release.yml"
    echo "release.yml créé : à commiter."
fi

status=0
if [ ! -f "$path/.distignore" ]; then
    echo "À faire : créer $path/.distignore (fichiers exclus du paquet)." >&2
    status=1
fi
if ! grep -qE '^[[:space:]*#@/]*Update URI:[[:space:]]*https://updates\.wearewp\.pro[[:space:]]*$' "$path/$slug.php" 2>/dev/null; then
    echo "À faire : ajouter l'en-tête « Update URI: https://updates.wearewp.pro » dans $slug.php." >&2
    status=1
fi
if [ ! -f "$path/wawp-updater/load.php" ]; then
    echo "À faire : copier wawp-updater/ dans le plugin et ajouter le chargement (voir docs/mise-en-place.md)." >&2
    status=1
fi
exit "$status"
