<?php
/**
 * Contrôle du paquet construit, juste avant le zip. Lecture seule, n'exécute jamais le code du plugin.
 * Usage : php check-package.php <dossier-construit (build/<slug>)> <slug> <version X.Y.Z>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dir = rtrim($argv[1] ?? '', '/\\');
$slug = $argv[2] ?? '';
$version = $argv[3] ?? '';
$errors = [];

$read = static function (string $path, int $limit = 0): ?string {
    if (!is_file($path)) {
        return null;
    }
    $content = $limit > 0 ? file_get_contents($path, false, null, 0, $limit) : file_get_contents($path);

    return $content === false ? null : str_replace("\r", "\n", $content);
};
// Même extraction d'en-tête que get_file_data() de WordPress.
$header = static function (string $content, string $name): ?string {
    $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi';
    if (preg_match($pattern, $content, $m) !== 1) {
        return null;
    }
    $value = trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));

    return $value === '' ? null : $value;
};

if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Dossier du paquet construit introuvable : « $dir ».\n");
    exit(1);
}
if (preg_match('/^[a-z0-9-]{1,64}\z/', $slug) !== 1) {
    $errors[] = "Slug invalide : « $slug ».";
}
if (preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}\z/', $version) !== 1) {
    $errors[] = "Version invalide : « $version » (format attendu X.Y.Z).";
}

$required = ['wawp-updater/load.php', 'wawp-updater/bootstrap.php'];
foreach (['config', 'validator', 'signature', 'client', 'updater', 'package'] as $class) {
    $required[] = "wawp-updater/src/class-$class.php";
}
foreach ($required as $file) {
    if (!is_file("$dir/$file")) {
        $errors[] = "$file absent du paquet.";
    }
}

$main = $read("$dir/$slug.php");
if ($main === null) {
    $errors[] = "Fichier principal $slug.php absent du paquet.";
} else {
    if (strpos($main, 'wawp-updater/load.php') === false) {
        $errors[] = "$slug.php ne charge pas wawp-updater/load.php.";
    }
    if (strpos($main, '\WAWP\Updater\register(') === false) {
        $errors[] = "$slug.php n'appelle pas \\WAWP\\Updater\\register(.";
    }
    $head = substr($main, 0, 8192);
    $declared = $header($head, 'Version');
    if ($declared !== $version) {
        $errors[] = 'En-tête Version (' . ($declared ?? 'absent') . ") différent de la version publiée ($version).";
    }
    if ($header($head, 'Update URI') !== 'https://updates.wearewp.pro') {
        $errors[] = 'En-tête Update URI absent ou différent de https://updates.wearewp.pro.';
    }
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
if (count($keys) !== 2 || count($validKeys) !== 2 || count(array_unique($validKeys)) !== 2) {
    $errors[] = 'PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes (wawp-updater/src/class-config.php).';
}

$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($entries as $entry) {
    if ($entry instanceof SplFileInfo && $entry->getFilename() === '.git') {
        $errors[] = 'Dossier .git présent dans le paquet : ' . substr(str_replace('\\', '/', $entry->getPathname()), strlen($dir) + 1) . '.';
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Paquet non conforme :\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Paquet conforme : $slug $version\n";
