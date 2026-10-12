# release-tools

Outils de publication des plugins WeAre[WP] et client de mise à jour `wawp-updater`.

- `wawp-updater/` : bibliothèque embarquée dans chaque plugin (PHP 7.4+, WordPress 5.8+). Vérifie les mises à jour sur `updates.wearewp.pro` avec la clé `WAWP_LICENSE_KEY` et refuse tout paquet dont la signature Ed25519 est invalide.
- `.github/workflows/plugin-release.yml` : workflow réutilisable, déclenché par un tag `vX.Y.Z` dans le dépôt d'un plugin.
- `scripts/`, `bin/` : contrôles, signature, envoi FTPS, génération des clés, préparation d'un dépôt.

Ce dépôt ne contient aucun secret. Mise en place : `docs/mise-en-place.md`.

Développement : `composer test`, `composer stan`, `composer cs`, `composer e2e` (nécessite le dépôt `updates-server` à côté).

Licence : GPL-2.0-or-later.
