# release-tools

Outils de publication des plugins et des thèmes WeAre[WP], clients de mise à jour `wawp-updater` (plugins) et `wawp-theme-updater` (thèmes).

- `wawp-updater/` : bibliothèque embarquée dans chaque plugin (PHP 7.4+, WordPress 5.8+). Vérifie les mises à jour sur `updates.wearewp.pro` avec la clé `WAWP_LICENSE_KEY` et refuse tout paquet dont la signature Ed25519 est invalide. Figée en version 1.0.0.
- `wawp-theme-updater/` : bibliothèque embarquée dans le thème FSE autonome d'un client (PHP 7.4+, WordPress 6.1+), mêmes garanties, chargée par `functions.php`.
- `.github/workflows/plugin-release.yml` et `.github/workflows/theme-release.yml` (nouveaux dépôts : `@v1.4`) : workflows réutilisables, déclenchés par un tag `vX.Y.Z` dans le dépôt d'un plugin ou d'un thème. Les dépôts existants sur `@v1` / `@v1.2` continuent de fonctionner ; ils passent à `@v1.4` en modifiant une ligne de `.github/workflows/release.yml`.
- `scripts/`, `bin/` : contrôles (`--type=theme` pour un thème), signature, envoi FTPS, génération des clés, préparation d'un dépôt.

Ce dépôt ne contient aucun secret. Mise en place : `docs/mise-en-place.md`.

Développement : `composer test`, `composer stan`, `composer cs`, `composer e2e` (nécessite le dépôt `updates-server` à côté).

Licence : GPL-2.0-or-later.
