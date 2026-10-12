<?php
/**
 * Contrôles d'un plugin avant construction du paquet. Lit les fichiers, n'exécute jamais le code du plugin.
 * Usage : php check-headers.php <dossier-plugin> <slug> <tag vX.Y.Z> <version minimale de wawp-updater>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dir = rtrim($argv[1] ?? '', '/\\');
$slug = $argv[2] ?? '';
$tag = $argv[3] ?? '';
$minUpdater = trim($argv[4] ?? '');
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
    fwrite(STDERR, "Dossier du plugin introuvable : « $dir ».\n");
    exit(1);
}
if (preg_match('/^\d+\.\d+\.\d+\z/', $minUpdater) !== 1) {
    fwrite(STDERR, "Version minimale de wawp-updater invalide : « $minUpdater » (format attendu X.Y.Z, config/min-updater-version.txt).\n");
    exit(1);
}
if (preg_match('/^[a-z0-9-]{1,64}\z/', $slug) !== 1) {
    $errors[] = "Slug invalide : « $slug » (le nom du dépôt doit être le slug du plugin, en minuscules).";
}
if (preg_match('/^v(\d{1,5}\.\d{1,5}\.\d{1,5})\z/', $tag, $m) === 1) {
    $version = $m[1];
} else {
    $errors[] = "Tag invalide : « $tag » (format attendu vX.Y.Z).";
}

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

$config = $read("$dir/wawp-updater/src/class-config.php");
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
    $errors[] = 'PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes (wawp-updater/src/class-config.php).';
}

if ($errors !== []) {
    fwrite(STDERR, "Contrôles en échec :\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Contrôles réussis : $slug $version\n";
