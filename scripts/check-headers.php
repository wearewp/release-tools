<?php
/**
 * Contrôles d'un plugin ou d'un thème avant construction du paquet. Lit les fichiers, n'exécute jamais leur code.
 * Usage : php check-headers.php [--type=plugin|theme] <dossier> <slug> <tag vX.Y.Z> <version minimale de la bibliothèque cliente>
 * Sans --type : plugin (comportement d'origine). Pour un thème, la bibliothèque est wawp-theme-updater.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$type = 'plugin';
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--type=', 7) === 0) {
        $type = substr($arg, 7);
    } else {
        $args[] = $arg;
    }
}
if ($type !== 'plugin' && $type !== 'theme') {
    fwrite(STDERR, "Type invalide : « $type » (plugin ou theme).\n");
    exit(1);
}
$isTheme = $type === 'theme';
$kind = $isTheme ? 'thème' : 'plugin';
$library = $isTheme ? 'wawp-theme-updater' : 'wawp-updater';
$minFile = $isTheme ? 'min-theme-updater-version.txt' : 'min-updater-version.txt';

$dir = rtrim($args[0] ?? '', '/\\');
$slug = $args[1] ?? '';
$tag = $args[2] ?? '';
$minUpdater = trim($args[3] ?? '');
$errors = [];
$version = '';

$read = static function (string $path, int $limit = 0): ?string {
    if (!is_file($path)) {
        return null;
    }
    $content = $limit > 0 ? file_get_contents($path, false, null, 0, $limit) : file_get_contents($path);

    return $content === false ? null : str_replace("\r", "\n", $content);
};
$header = static function (string $content, string $name): ?string {
    $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi';
    if (preg_match($pattern, $content, $m) !== 1) {
        return null;
    }
    $value = trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));

    return $value === '' ? null : $value;
};

if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Dossier du $kind introuvable : « $dir ».\n");
    exit(1);
}
if (preg_match('/^\d+\.\d+\.\d+\z/', $minUpdater) !== 1) {
    fwrite(STDERR, "Version minimale de $library invalide : « $minUpdater » (format attendu X.Y.Z, config/$minFile).\n");
    exit(1);
}
if (preg_match('/^[a-z0-9-]{1,64}\z/', $slug) !== 1) {
    $errors[] = "Slug invalide : « $slug » (le nom du dépôt doit être le slug du $kind, en minuscules).";
}
if (preg_match('/^v(\d{1,5}\.\d{1,5}\.\d{1,5})\z/', $tag, $m) === 1) {
    $version = $m[1];
} else {
    $errors[] = "Tag invalide : « $tag » (format attendu vX.Y.Z).";
}

if ($isTheme) {
    $style = $read("$dir/style.css", 8192);
    if ($style === null) {
        $errors[] = 'style.css absent.';
    } else {
        $name = $header($style, 'Theme Name');
        if ($name === null || preg_match('/^[^\p{Cc}]{1,200}\z/u', $name) !== 1) {
            $errors[] = 'En-tête Theme Name absent ou invalide (UTF-8, 200 caractères au plus, sans caractère de contrôle).';
        }
        $declared = $header($style, 'Version');
        if ($version !== '' && $declared !== $version) {
            $errors[] = 'En-tête Version (' . ($declared ?? 'absent') . ") différent du tag ($version).";
        }
        if ($header($style, 'Update URI') !== 'https://updates.wearewp.pro') {
            $errors[] = 'En-tête Update URI absent ou différent de https://updates.wearewp.pro.';
        }
        $themeUri = $header($style, 'Theme URI');
        if ($themeUri !== null && preg_match('/^https?:\/\/[^\s"<>\\\\\p{Cc}]{1,2040}\z/u', $themeUri) !== 1) {
            $errors[] = 'En-tête Theme URI invalide (adresse http ou https, sans espace, guillemet, chevron, barre oblique inverse ni caractère de contrôle, 2048 caractères au plus).';
        }
        $requires = $header($style, 'Requires at least');
        if ($requires === null || preg_match('/^\d{1,5}(?:\.\d{1,5}){1,3}\z/', $requires) !== 1 || version_compare($requires, '6.1', '<')) {
            $errors[] = 'En-tête Requires at least absent ou inférieur à 6.1 (filtre update_themes_{hôte} de WordPress).';
        }
        $requiresPhp = $header($style, 'Requires PHP');
        if ($requiresPhp === null || preg_match('/^\d{1,5}(?:\.\d{1,5}){1,3}\z/', $requiresPhp) !== 1) {
            $errors[] = 'En-tête Requires PHP absent ou invalide.';
        }
        if ($header($style, 'Text Domain') !== $slug) {
            $errors[] = "En-tête Text Domain absent ou différent du slug ($slug) : le client s'en sert pour vérifier le dossier du thème.";
        }
        if ($header($style, 'Template') !== null) {
            $errors[] = 'En-tête Template présent : les thèmes enfants ne sont pas pris en charge.';
        }
    }
    if (is_file("$dir/$slug.php")) {
        $errors[] = "Fichier $slug.php présent à la racine : le serveur refuserait le paquet (plugin et thème ambigus).";
    }
    if (!is_file("$dir/functions.php")) {
        $errors[] = 'functions.php absent.';
    }
    if (!is_file("$dir/.distignore")) {
        $errors[] = 'Fichier .distignore absent.';
    }
    $themeConfig = $read("$dir/wawp-theme-updater/src/class-config.php");
    if ($themeConfig === null || preg_match("/const VERSION = '(\d+\.\d+\.\d+)';/", $themeConfig, $m) !== 1) {
        $errors[] = 'wawp-theme-updater absent ou version illisible (wawp-theme-updater/src/class-config.php).';
    } elseif (version_compare($m[1], $minUpdater, '<')) {
        $errors[] = "wawp-theme-updater {$m[1]} trop ancien (minimum $minUpdater).";
    }
} else {
    $main = $read("$dir/$slug.php", 8192);
    if ($main === null) {
        $errors[] = "Fichier principal $slug.php absent.";
    } else {
        $name = $header($main, 'Plugin Name');
        if ($name === null || preg_match('/^[^\p{Cc}]{1,200}\z/u', $name) !== 1) {
            $errors[] = 'En-tête Plugin Name absent ou invalide (UTF-8, 200 caractères au plus, sans caractère de contrôle).';
        }
        $declared = $header($main, 'Version');
        if ($version !== '' && $declared !== $version) {
            $errors[] = 'En-tête Version (' . ($declared ?? 'absent') . ") différent du tag ($version).";
        }
        if ($header($main, 'Update URI') !== 'https://updates.wearewp.pro') {
            $errors[] = 'En-tête Update URI absent ou différent de https://updates.wearewp.pro.';
        }
        if ($header($main, 'GitHub Plugin URI') !== null) {
            $errors[] = 'En-tête GitHub Plugin URI présent : retirer les en-têtes Git Updater.';
        }
    }

    $readme = $read("$dir/readme.txt");
    if ($readme === null) {
        $errors[] = 'readme.txt absent.';
    } elseif (preg_match('/^Stable tag:[ \t]*(\S+)[ \t]*$/mi', $readme, $m) !== 1 || ($version !== '' && $m[1] !== $version)) {
        $errors[] = "Stable tag du readme.txt absent ou différent de $version.";
    }

    if (!is_file("$dir/.distignore")) {
        $errors[] = 'Fichier .distignore absent.';
    }
    if (is_dir("$dir/plugin-update-checker") || is_dir("$dir/vendor/yahnis-elsts/plugin-update-checker")) {
        $errors[] = 'Plugin Update Checker encore embarqué : le retirer.';
    }

    $load = $read("$dir/wawp-updater/load.php");
    if ($load === null || preg_match("/\\\$wawp_updater_version = '(\d+\.\d+\.\d+)';/", $load, $m) !== 1) {
        $errors[] = 'wawp-updater absent ou version illisible (wawp-updater/load.php).';
    } elseif (version_compare($m[1], $minUpdater, '<')) {
        $errors[] = "wawp-updater {$m[1]} trop ancien (minimum $minUpdater).";
    }
}

$config = $read("$dir/$library/src/class-config.php");
$keys = [];
if ($config !== null && preg_match('/const PUBLIC_KEYS = array\(([^)]*)\);/', $config, $m) === 1) {
    preg_match_all("/'([A-Za-z0-9+\\/=]+)'/", $m[1], $found);
    $keys = $found[1];
}
$validKeys = array_filter($keys, static function (string $key): bool {
    $raw = base64_decode($key, true);

    return $raw !== false && strlen($raw) === 32;
});
if (count($validKeys) !== 2 || count(array_unique($validKeys)) !== 2) {
    $errors[] = "PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes ($library/src/class-config.php).";
}

if ($errors !== []) {
    fwrite(STDERR, "Contrôles en échec :\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Contrôles réussis : $slug $version\n";
