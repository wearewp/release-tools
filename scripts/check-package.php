<?php
/**
 * Contrôle du paquet construit, juste avant le zip. Lecture seule, n'exécute jamais le code du plugin ou du thème.
 * Usage : php check-package.php [--type=plugin|theme] <dossier-construit (build/<slug>)> <slug> <version X.Y.Z>
 * Sans --type : plugin (comportement d'origine).
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
$library = $isTheme ? 'wawp-theme-updater' : 'wawp-updater';

$dir = rtrim($args[0] ?? '', '/\\');
$slug = $args[1] ?? '';
$version = $args[2] ?? '';
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

if ($isTheme) {
    $required = ['wawp-theme-updater/load.php', 'wawp-theme-updater/bootstrap.php'];
    foreach (['config', 'validator', 'theme-validator', 'signature', 'client', 'package', 'updater'] as $class) {
        $required[] = "wawp-theme-updater/src/class-$class.php";
    }
    foreach ($required as $file) {
        if (!is_file("$dir/$file")) {
            $errors[] = "$file absent du paquet.";
        }
    }

    $functions = $read("$dir/functions.php");
    if ($functions === null) {
        $errors[] = 'functions.php absent du paquet.';
    } else {
        if (strpos($functions, 'wawp-theme-updater/load.php') === false) {
            $errors[] = 'functions.php ne charge pas wawp-theme-updater/load.php.';
        }
        if (strpos($functions, '\WAWP\ThemeUpdater\register(') === false) {
            $errors[] = "functions.php n'appelle pas \\WAWP\\ThemeUpdater\\register(.";
        }
    }

    $style = $read("$dir/style.css", 8192);
    if ($style === null) {
        $errors[] = 'style.css absent du paquet.';
    } else {
        $declared = $header($style, 'Version');
        if ($declared !== $version) {
            $errors[] = 'En-tête Version de style.css (' . ($declared ?? 'absent') . ") différent de la version publiée ($version).";
        }
        if ($header($style, 'Update URI') !== 'https://updates.wearewp.pro') {
            $errors[] = 'En-tête Update URI de style.css absent ou différent de https://updates.wearewp.pro.';
        }
    }
    if (is_file("$dir/$slug.php")) {
        $errors[] = "$slug.php présent dans le paquet d'un thème : le serveur le refuserait.";
    }
} else {
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
if (count($keys) !== 2 || count($validKeys) !== 2 || count(array_unique($validKeys)) !== 2) {
    $errors[] = "PUBLIC_KEYS doit contenir exactement deux clés publiques Ed25519 distinctes ($library/src/class-config.php).";
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
