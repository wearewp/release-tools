# Mise en place de la publication des plugins WeAre[WP]

Procédure unique, à suivre dans l'ordre. Les étapes marquées 🔒 manipulent des secrets : Thierry les exécute lui-même, dans son propre terminal, jamais dans une session automatisée.

## 1. Clés de signature 🔒

Dans un terminal local, depuis ce dépôt :

```
php bin/keygen.php
```

- Clé privée ACTIVE : la copier dans le gestionnaire de mots de passe, puis dans le secret d'organisation `SIGNING_KEY` (étape 2).
- Clé privée de SECOURS : uniquement dans le gestionnaire de mots de passe. Jamais sur GitHub, jamais sur un serveur.
- Les deux clés publiques : à transmettre pour l'étape 4 (elles ne sont pas secrètes).

Effacer ensuite le terminal (`clear`) et fermer la fenêtre.

## 2. Secrets d'organisation GitHub 🔒

Le dépôt privé `wearewp/wawp-test-plugin` doit déjà exister (plugin de test servant à valider la chaîne, créé à l'étape 6 ou lors de la mise en service avec Claude), sinon `--repos wawp-test-plugin` échoue.

Pour chaque secret, la commande demande la valeur de façon masquée :

```
gh secret set SIGNING_KEY --org wearewp --visibility selected --repos wawp-test-plugin
gh secret set FTP_HOST --org wearewp --visibility selected --repos wawp-test-plugin
gh secret set FTP_USER --org wearewp --visibility selected --repos wawp-test-plugin
gh secret set FTP_PASSWORD --org wearewp --visibility selected --repos wawp-test-plugin
```

Les secrets d'organisation doivent impérativement être créés avec `--visibility selected` : sinon `bin/setup-repo.sh` ne peut pas y ajouter de dépôts.

`FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` : le compte FTP dédié créé dans cPanel, limité à `updates-data/incoming`. `FTP_HOST` doit être le nom d'hôte couvert par le certificat FTPS d'o2switch (celui du serveur mutualisé, affiché dans cPanel), et non `updates.wearewp.pro` ni une adresse IP : l'envoi vérifie le certificat et échoue sinon. Les autres dépôts de plugins sont ajoutés ensuite par `bin/setup-repo.sh` (étape 6), sans jamais retaper les valeurs.

## 3. Serveur o2switch

- Créer `~/updates-data/signing.pub` avec le bloc « Contenu de ~/updates-data/signing.pub » affiché par `keygen.php` (clé active puis clé de secours).
- Ajouter maintenant la tâche cron d'ingestion : `*/5 * * * * <php> ~/updates-data/bin/ingest.php` (voir `docs/deploiement-o2switch.md` du dépôt updates-server).
- Lancer une fois `<php> ~/updates-data/bin/ingest.php` : aucune sortie, code 0.

## 4. Clés publiques dans wawp-updater

Placer la ligne `const PUBLIC_KEYS = array( '…', '…' );` affichée par `keygen.php` dans `wawp-updater/src/class-config.php`, faire relire, puis fusionner. Le tag `v1` n'est pas créé ici : il l'est à l'étape 5, une fois protégé.

## 5. Ruleset des tags de release-tools

Le tag `v1` est la référence utilisée par tous les plugins. La politique Actions de l'organisation (Settings > Actions > General) doit autoriser les workflows réutilisables de `wearewp/*`, sinon l'appel depuis les dépôts de plugins est refusé. Les commandes de cette section et de la suivante s'exécutent dans Git Bash, avec `gh` authentifié par un compte propriétaire de l'organisation `wearewp` (vérifier avec `gh auth status`).

D'abord protéger les tags :

```
gh api -X POST repos/wearewp/release-tools/rulesets --input - <<'JSON'
{ "name": "Tags de version immuables", "target": "tag", "enforcement": "active",
  "conditions": { "ref_name": { "include": ["refs/tags/v*"], "exclude": [] } },
  "rules": [ { "type": "deletion" }, { "type": "update" }, { "type": "non_fast_forward" } ] }
JSON
```

Ensuite seulement, créer le tag depuis un clone de release-tools, sur `main` après la fusion de l'étape 4 :

```
git checkout main && git pull
git tag v1
git push origin v1
```

## 6. Préparer un dépôt de plugin

Le dépôt doit appartenir à l'organisation `wearewp`, être privé et porter le nom du slug du plugin.

1. Copier `wawp-updater/` (version taguée `v1`) à la racine du plugin.
2. Dans le fichier principal `<slug>.php` : ajouter l'en-tête `Update URI: https://updates.wearewp.pro` et, après le contrôle `ABSPATH` :
   ```php
   require __DIR__ . '/wawp-updater/load.php';
   \WAWP\Updater\register( __FILE__ );
   ```
3. Créer `.distignore` (fichiers exclus du paquet : `/.git`, `/.github`, `/tests`, `/node_modules`, fichiers de configuration de développement…).
4. Retirer Plugin Update Checker et les en-têtes Git Updater s'il y en a.
5. Lancer, dans Git Bash, depuis le dossier de release-tools (`gh` authentifié comme à l'étape 5) : `bash bin/setup-repo.sh wearewp/<slug> <chemin-local-du-plugin>`, puis commiter `.github/workflows/release.yml`.

## 7. Publier une version

Mettre à jour l'en-tête `Version` et le `Stable tag` du `readme.txt`, commiter, puis :

```
git tag vX.Y.Z
git push origin vX.Y.Z
```

Le workflow contrôle, construit, signe, envoie sur o2switch et archive le paquet dans la Release GitHub. Le cron d'ingestion le publie dans les 5 minutes ; sans action de l'administrateur, un site peut mettre jusqu'à environ 24 h à la voir (cache de WordPress de 12 h, puis cache du client de 12 h) ; « Tableau de bord > Mises à jour > Rechercher les mises à jour » vide les deux caches et la fait apparaître aussitôt.

Si plusieurs tags sont poussés coup sur coup, la règle de concurrence du job d'envoi peut annuler un run en attente (seul le plus récent attend son tour). Vérifier l'onglet Actions : relancer le run annulé (« Re-run jobs ») ou repousser le tag concerné.

## 8. Rotation de la clé de signature (fuite de la clé active)

Suivre la section 11 de la spec (`docs/superpowers/specs/2026-10-12-serveur-mises-a-jour-design.md` dans le dépôt updates-server) : passer `SIGNING_KEY` sur la clé de secours avec `gh secret set SIGNING_KEY --org wearewp --visibility selected --repos <liste>` (attention : `--repos` remplace la liste existante, y inclure tous les dépôts de plugins), générer une nouvelle paire de secours, publier une version de `wawp-updater` dont `PUBLIC_KEYS` vaut `array( ancienne clé de secours, nouvelle clé de secours )`, l'intégrer dans tous les plugins et les publier, puis retirer l'ancienne clé publique de `signing.pub`.

La version de rotation de `wawp-updater` incrémente `$wawp_updater_version` dans `load.php` et `Config::VERSION` dans `src/class-config.php`, toujours égales (par exemple `1.0.0` vers `1.1.0`) : c'est la copie la plus récente qui est chargée sur un site. Une fois cette version taguée, relever `config/min-updater-version.txt` sur la branche `main` de release-tools à cette même version : chaque publication lit aussi ce fichier sur `main` et retient la plus haute des deux valeurs, ce qui bloque la publication d'un plugin qui embarque encore l'ancien client, même si son workflow est épinglé sur un ancien tag.

## Limite connue

WordPress n'appelle les filtres `Update URI` qu'après une réponse réussie d'`api.wordpress.org`. Si ce service est injoignable depuis un site, les mises à jour WeAre[WP] n'y apparaissent pas tant que la situation dure ; elles réapparaissent dès le contrôle suivant.
