<?php
/**
 * Bout en bout : vrai WordPress (SQLite) + vrai serveur de mises à jour (plan 1) + scripts de publication.
 * Prérequis : dépôt updates-server à côté (UPDATES_SERVER_DIR), réseau au premier lancement (cache vérifié par empreinte).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$serverRepo = getenv('UPDATES_SERVER_DIR') ?: dirname($root) . '/updates.wearewp.pro';
$e2e = __DIR__;
$tmp = "$e2e/tmp";
$cache = "$e2e/cache";
$versions = json_decode((string) file_get_contents("$e2e/versions.json"), true, 8, JSON_THROW_ON_ERROR);
$port = 8767;
$endpoint = "http://127.0.0.1:$port";
$slug = 'wawp-test-plugin';
$basename = "$slug/$slug.php";
$themeSlug = 'wawp-test-theme';
$failures = 0;
$masks = array();

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$masks): void {
    foreach ($masks as $mask) {
        $detail = str_replace($mask, '[masqué]', $detail);
    }
    echo ($ok ? 'OK     ' : 'ÉCHEC  ') . $label . ($ok || $detail === '' ? '' : "\n       $detail") . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array{int, string} */
$run = static function (array $command, array $env = []): array {
    $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    if (!is_resource($process)) {
        return [1, 'lancement impossible'];
    }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out];
};

$remove = static function (string $path) use (&$remove): void {
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove("$path/$entry");
        }
    }
    @rmdir($path);
};

$copy = static function (string $source, string $target) use (&$copy): void {
    if (is_file($source)) {
        @mkdir(dirname($target), 0777, true);
        copy($source, $target);
        return;
    }
    @mkdir($target, 0777, true);
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $copy("$source/$entry", "$target/$entry");
        }
    }
};

$fetch = static function (string $url, string $dest, string $algo, string $expected): void {
    if (!is_file($dest)) {
        @mkdir(dirname($dest), 0777, true);
        $curl = curl_init($url);
        $handle = fopen("$dest.part", 'wb');
        curl_setopt_array($curl, [CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => 300]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        fclose($handle);
        if ($ok !== true) {
            @unlink("$dest.part");
            throw new RuntimeException("Téléchargement impossible ($url) : $error");
        }
        rename("$dest.part", $dest);
    }
    if (!hash_equals(strtolower($expected), hash_file($algo, $dest))) {
        @unlink($dest);
        throw new RuntimeException("Empreinte $algo incorrecte pour $dest");
    }
};

$unzip = static function (string $zip, string $target): void {
    $archive = new ZipArchive();
    if ($archive->open($zip) !== true || !$archive->extractTo($target)) {
        throw new RuntimeException("Extraction impossible : $zip");
    }
    $archive->close();
};

// 1. Téléchargements vérifiés.
$wpZip = "$cache/wordpress-{$versions['wordpress']['version']}.zip";
$sqliteZip = "$cache/sqlite-{$versions['sqlite']['version']}.zip";
$wpcli = "$cache/wp-cli-{$versions['wpcli']['version']}.phar";
$fetch("https://wordpress.org/wordpress-{$versions['wordpress']['version']}.zip", $wpZip, 'sha1', $versions['wordpress']['sha1']);
$fetch($versions['sqlite']['url'], $sqliteZip, 'sha256', $versions['sqlite']['sha256']);
$fetch("https://github.com/wp-cli/wp-cli/releases/download/v{$versions['wpcli']['version']}/wp-cli-{$versions['wpcli']['version']}.phar", $wpcli, 'sha512', $versions['wpcli']['sha512']);

$remove($tmp);
mkdir($tmp, 0777, true);

// 2. Serveur de mises à jour, arborescence de production, adresse locale.
$web = "$tmp/srv/updates.wearewp.pro";
$data = "$tmp/srv/updates-data";
$copy("$serverRepo/public/index.php", "$web/index.php");
$copy("$serverRepo/app", "$data/app");
$copy("$serverRepo/bin", "$data/bin");
file_put_contents("$data/app/config.php", "<?php\n\ndeclare(strict_types=1);\n\nuse WAWP\\UpdateServer\\Config;\n\nreturn new Config(dataRoot: dirname(__DIR__), baseUrl: '$endpoint');\n");
[$code] = $run([PHP_BINARY, "$data/bin/install.php"]);
$check('installation du serveur', $code === 0);

$pair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));
file_put_contents("$data/signing.pub", "# active\n$public\n");

require "$data/app/autoload.php";
$key = \WAWP\UpdateServer\Licence\KeyGenerator::key();
$masks[] = $key;
$hasher = new \WAWP\UpdateServer\Licence\KeyHasher(\WAWP\UpdateServer\Secrets::loadHex("$data/secrets/key_pepper"));
$writeLicences = static function (bool $active, bool $withTheme = true) use ($data, $hasher, $key, $slug, $themeSlug): void {
    file_put_contents("$data/licences.json", (string) json_encode(['schema' => 1, 'licences' => [[
        'id' => 'lic_e2eclient', 'key_hash' => $hasher->hash($key), 'client' => 'E2E', 'domains' => ['127.0.0.1'],
        'plugins' => [$slug], 'themes' => $withTheme ? [$themeSlug] : [], 'expires' => null, 'active' => $active,
    ]]], JSON_PRETTY_PRINT));
};
$writeLicences(true);

/** @return list<string> Toutes les lignes des journaux d'accès du serveur. */
$accessLines = static function () use ($data): array {
    $lines = array();
    $files = glob("$data/logs/access-*.log") ?: array();
    sort($files);
    foreach ($files as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
            $lines[] = $line;
        }
    }

    return $lines;
};

// 3. Construction et publication d'une version du plugin de test.
$makePlugin = static function (string $version, string $target) use ($e2e, $root, $copy, $endpoint, $public): void {
    foreach (['wawp-test-plugin.php', 'readme.txt', '.distignore'] as $file) {
        @mkdir($target, 0777, true);
        file_put_contents("$target/$file", str_replace('{{VERSION}}', $version, (string) file_get_contents("$e2e/fixture/wawp-test-plugin/$file")));
    }
    $copy("$root/wawp-updater", "$target/wawp-updater");
    $config = (string) file_get_contents("$target/wawp-updater/src/class-config.php");
    $config = (string) preg_replace("/const ENDPOINT = '[^']*';/", "const ENDPOINT = '$endpoint';", $config);
    $config = (string) preg_replace('/const PUBLIC_KEYS = array\([^)]*\);/', "const PUBLIC_KEYS = array( '$public' );", $config);
    file_put_contents("$target/wawp-updater/src/class-config.php", $config);
};

$publish = static function (string $version, bool $corruptAfter = false) use ($tmp, $data, $slug, $makePlugin, $remove, $run, $root, $secret): bool {
    $build = "$tmp/build/$slug";
    $remove("$tmp/build");
    $makePlugin($version, $build);
    @unlink("$build/.distignore");
    $zipPath = "$data/incoming/$slug-$version.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen("$tmp/build/")));
        $zip->addFile($file->getPathname(), $relative);
    }
    $zip->close();
    [$signCode] = $run([PHP_BINARY, "$root/scripts/sign.php", $zipPath], ['SIGNING_KEY' => $secret]);
    [$ingestCode] = $run([PHP_BINARY, "$data/bin/ingest.php"]);
    $published = is_file("$data/packages/$slug/$slug-$version.zip");
    if ($corruptAfter && $published) {
        file_put_contents("$data/packages/$slug/$slug-$version.zip", 'corruption', FILE_APPEND);
    }

    return $signCode === 0 && $ingestCode === 0 && $published;
};

// 3 bis. Construction et publication d'une version du thème de test.
$makeTheme = static function (string $version, string $target) use ($e2e, $root, $copy, $endpoint, $public): void {
    foreach (['style.css', 'functions.php', 'theme.json', 'templates/index.html'] as $file) {
        @mkdir(dirname("$target/$file"), 0777, true);
        file_put_contents("$target/$file", str_replace('{{VERSION}}', $version, (string) file_get_contents("$e2e/fixture/wawp-test-theme/$file")));
    }
    $copy("$root/wawp-theme-updater", "$target/wawp-theme-updater");
    $config = (string) file_get_contents("$target/wawp-theme-updater/src/class-config.php");
    $config = (string) preg_replace("/const ENDPOINT = '[^']*';/", "const ENDPOINT = '$endpoint';", $config);
    $config = (string) preg_replace('/const PUBLIC_KEYS = array\([^)]*\);/', "const PUBLIC_KEYS = array( '$public' );", $config);
    file_put_contents("$target/wawp-theme-updater/src/class-config.php", $config);
};

$publishTheme = static function (string $version, bool $corruptAfter = false) use ($tmp, $data, $themeSlug, $makeTheme, $remove, $run, $root, $secret): bool {
    $build = "$tmp/build-theme/$themeSlug";
    $remove("$tmp/build-theme");
    $makeTheme($version, $build);
    $zipPath = "$data/incoming/$themeSlug-$version.zip";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen("$tmp/build-theme/")));
        $zip->addFile($file->getPathname(), $relative);
    }
    $zip->close();
    [$signCode] = $run([PHP_BINARY, "$root/scripts/sign.php", $zipPath], ['SIGNING_KEY' => $secret]);
    [$ingestCode] = $run([PHP_BINARY, "$data/bin/ingest.php"]);
    $published = is_file("$data/packages/$themeSlug/$themeSlug-$version.zip");
    if ($corruptAfter && $published) {
        file_put_contents("$data/packages/$themeSlug/$themeSlug-$version.zip", 'corruption', FILE_APPEND);
    }

    return $signCode === 0 && $ingestCode === 0 && $published;
};

// 4. WordPress avec SQLite.
$wpDir = "$tmp/wp";
$unzip($wpZip, $tmp);
rename("$tmp/wordpress", $wpDir);
$unzip($sqliteZip, "$wpDir/wp-content/plugins");
$dbCopy = (string) file_get_contents("$wpDir/wp-content/plugins/sqlite-database-integration/db.copy");
file_put_contents("$wpDir/wp-content/db.php", str_replace(
    ['{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '{SQLITE_PLUGIN}'],
    [str_replace('\\', '/', "$wpDir/wp-content/plugins/sqlite-database-integration"), 'sqlite-database-integration/load.php'],
    $dbCopy
));
$salts = '';
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
    $salts .= "define( '$salt', '" . bin2hex(random_bytes(32)) . "' );\n";
}
file_put_contents("$wpDir/wp-config.php", "<?php\n"
    . "define( 'DB_NAME', 'e2e' );\n"
    . "define( 'DB_USER', 'e2e' );\n"
    . "define( 'DB_PASSWORD', 'e2e' );\n"
    . "define( 'DB_HOST', 'localhost' );\n"
    . "define( 'DB_DIR', __DIR__ . '/wp-content/database/' );\n"
    . "define( 'DB_FILE', 'e2e.sqlite' );\n"
    . "define( 'WAWP_LICENSE_KEY', '$key' );\n"
    . $salts
    . "\$table_prefix = 'wp_';\n"
    . "define( 'WP_DEBUG', false );\n"
    . "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\n"
    . "require_once ABSPATH . 'wp-settings.php';\n");
@mkdir("$wpDir/wp-content/mu-plugins", 0777, true);
file_put_contents("$wpDir/wp-content/mu-plugins/e2e-reseau-local.php", "<?php\n"
    . "// Réseau du test : 127.0.0.1 autorisé, api.wordpress.org simulé, tout le reste refusé.\n"
    . "add_filter( 'http_request_host_is_external', static function ( \$external, \$host ) { return \$external || '127.0.0.1' === \$host; }, 10, 2 );\n"
    . "add_filter( 'http_allowed_safe_ports', static function ( \$ports ) { \$ports[] = $port; return \$ports; } );\n"
    . "add_filter( 'pre_http_request', static function ( \$pre, \$args, \$url ) {\n"
    . "\t\$host = (string) wp_parse_url( \$url, PHP_URL_HOST );\n"
    . "\tif ( '127.0.0.1' === \$host ) { return \$pre; }\n"
    . "\tif ( 'api.wordpress.org' === \$host && false !== strpos( \$url, '/themes/info/' ) ) { return array( 'headers' => array(), 'body' => '{\"error\":\"Theme not found\"}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ); }\n"
    . "\tif ( 'api.wordpress.org' === \$host ) { return array( 'headers' => array(), 'body' => '{\"plugins\":[],\"translations\":[],\"no_update\":[],\"themes\":[]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ); }\n"
    . "\treturn new WP_Error( 'e2e_reseau_bloque', \$host );\n"
    . "}, 10, 3 );\n");

$wp = static function (string ...$args) use ($run, $wpcli, $wpDir): array {
    return $run(array_merge([PHP_BINARY, $wpcli, "--path=$wpDir", '--skip-themes'], $args));
};
// Commandes WP-CLI avec le thème actif chargé (son functions.php charge wawp-theme-updater).
$wpTheme = static function (string ...$args) use ($run, $wpcli, $wpDir): array {
    return $run(array_merge([PHP_BINARY, $wpcli, "--path=$wpDir"], $args));
};
[$code, $out] = $wp('core', 'install', '--url=http://127.0.0.1:8768', '--title=E2E', '--admin_user=e2e', '--admin_password=' . bin2hex(random_bytes(16)), '--admin_email=e2e@example.test', '--skip-email');
$check('installation de WordPress (SQLite)', $code === 0, $out);

$makePlugin('1.0.0', "$wpDir/wp-content/plugins/$slug");
[$code, $out] = $wp('plugin', 'activate', $slug);
$check('activation du plugin de test 1.0.0', $code === 0, $out);

$version = static function () use ($wp, $slug): string {
    return trim($wp('plugin', 'get', $slug, '--field=version')[1]);
};
// Renvoie la version proposée ('' si aucune) ou '#erreur' si WP-CLI échoue ou renvoie un JSON sans l'entrée du plugin.
$updateVersion = static function () use ($wp, $slug): string {
    [$code, $out] = $wp('plugin', 'list', "--name=$slug", '--fields=name,update_version', '--format=json');
    $list = $code === 0 ? json_decode($out, true) : null;
    if (!is_array($list) || !isset($list[0]['name'], $list[0]['update_version']) || $list[0]['name'] !== $slug) {
        return '#erreur';
    }

    return (string) $list[0]['update_version'];
};
$flush = static function () use ($wp): void {
    $wp('eval', 'delete_site_transient( "wawp_updater_check" ); delete_site_transient( "update_plugins" );');
};

// 5. Serveur PHP intégré pour l'API de mises à jour.
$probe = @fsockopen('127.0.0.1', $port);
if ($probe !== false) {
    fclose($probe);
    fwrite(STDERR, "Le port $port répond déjà : un autre serveur occupe l'adresse de test.\n");
    exit(1);
}
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $web, "$web/index.php"], [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes);
$listening = false;
for ($i = 0; $i < 50 && !$listening; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket !== false) {
        fclose($socket);
        $listening = true;
    } else {
        usleep(100000);
    }
}
if (!$listening) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fwrite(STDERR, "Le serveur PHP intégré ne répond pas sur le port $port.\n");
    exit(1);
}

try {
    // a. Une mise à jour signée est proposée puis installée.
    $check('publication 1.1.0', $publish('1.1.0'));
    $check('mise à jour 1.1.0 proposée', $updateVersion() === '1.1.0');
    [$code, $out] = $wp('plugin', 'update', $slug);
    $check('mise à jour 1.1.0 installée', $code === 0 && $version() === '1.1.0', $out);

    [$leakCode, $leak] = $wp('eval', 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_value LIKE %s", "%" . WAWP_LICENSE_KEY . "%" ) ), "|", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s", "%wawp_updater_check%" ) );');
    $check('clé absente de la base (contrôle positif : cache présent)', $leakCode === 0 && preg_match('/^0\|([1-9]\d*)$/', trim($leak)) === 1, $leak);

    // Plugin à jour : entrée no_update (bascule des mises à jour automatiques), aucune offre.
    $flush();
    [$noUpdateCode, $noUpdate] = $wp('eval', 'wp_update_plugins(); $t = get_site_transient( "update_plugins" ); echo isset( $t->response["' . $basename . '"] ) ? "offre" : "", isset( $t->no_update["' . $basename . '"] ) ? $t->no_update["' . $basename . '"]->new_version . "|" . $t->no_update["' . $basename . '"]->package : "absent";');
    $check('plugin à jour rangé dans no_update', $noUpdateCode === 0 && trim($noUpdate) === '1.1.0|', $noUpdate);

    // b. Jeton périmé en cache : le téléchargement obtient un jeton frais.
    $check('publication 1.2.0', $publish('1.2.0'));
    $flush();
    $check('mise à jour 1.2.0 proposée', $updateVersion() === '1.2.0');
    [$injectCode, $injectOut] = $wp('eval', '$old = get_site_transient( "wawp_updater_check" ); $data = json_decode( $old["body"], true ); foreach ( array_keys( $data["plugins"] ) as $s ) { $data["plugins"][ $s ]["package"] = "' . $endpoint . '/v1/download/perime.jeton"; } $old["body"] = wp_json_encode( $data ); set_site_transient( "wawp_updater_check", $old, 3600 );'
        . '$t = get_site_transient( "update_plugins" ); $t->response["' . $basename . '"]->package = "' . $endpoint . '/v1/download/perime.jeton"; set_site_transient( "update_plugins", $t );');
    $check('injection du jeton périmé en cache', $injectCode === 0, $injectOut);
    [$readCode, $readOut] = $wp('eval', '$t = get_site_transient( "update_plugins" ); $c = get_site_transient( "wawp_updater_check" ); echo ( isset( $t->response["' . $basename . '"] ) && false !== strpos( (string) $t->response["' . $basename . '"]->package, "perime.jeton" ) ) ? "A" : "a", ( is_array( $c ) && false !== strpos( (string) $c["body"], "perime.jeton" ) ) ? "B" : "b";');
    $check('jeton périmé bien présent dans les deux caches avant mise à jour', $readCode === 0 && trim($readOut) === 'AB', $readOut);
    $linesBefore = count($accessLines());
    [$code, $out] = $wp('plugin', 'update', $slug);
    $check('mise à jour 1.2.0 avec jeton frais', $code === 0 && $version() === '1.2.0', $out);
    $newLines = array_slice($accessLines(), $linesBefore);
    $joined = implode("\n", $newLines);
    $downloads = array_filter($newLines, static function (string $line): bool {
        return str_contains($line, '"event":"download"') && str_contains($line, '"version":"1.2.0"');
    });
    $check('journal : téléchargement 1.2.0 enregistré', count($downloads) >= 1, $joined);
    $check('journal : jeton périmé jamais présenté', !str_contains($joined, 'perime.jeton') && !str_contains($joined, 'token_invalid') && !str_contains($joined, 'download_denied'), $joined);

    // c. Paquet altéré sur le serveur : signature refusée, plugin intact.
    $check('publication 1.3.0 puis altération', $publish('1.3.0', true));
    $flush();
    [$code, $out] = $wp('plugin', 'update', $slug);
    $check('paquet altéré refusé', $code !== 0 && str_contains($out, 'Signature du paquet WeAre[WP] invalide') && $version() === '1.2.0', $out);
    $check('plugin toujours présent et actif', is_file("$wpDir/wp-content/plugins/$basename") && trim($wp('plugin', 'is-active', $slug)[1]) === '' && $wp('plugin', 'is-active', $slug)[0] === 0);

    // d. Licence révoquée : plus aucune mise à jour proposée.
    $writeLicences(false);
    $flush();
    $linesBefore = count($accessLines());
    $check('licence révoquée : aucune mise à jour', $updateVersion() === '');
    $denied = array_filter(array_slice($accessLines(), $linesBefore), static function (string $line): bool {
        return str_contains($line, '"event":"check_denied"') && str_contains($line, '"reason":"inactive"');
    });
    $check('licence révoquée : refus inactive journalisé', count($denied) >= 1);
    $writeLicences(true);

    // f. Thème : offre, marqueur dans le cache, jeton frais, installation avec wawp-updater actif, paquet altéré refusé.
    $makeTheme('1.0.0', "$wpDir/wp-content/themes/$themeSlug");
    [$code, $out] = $wpTheme('theme', 'activate', $themeSlug);
    $check('activation du thème de test 1.0.0', $code === 0, $out);
    $themeVersion = static function () use ($wpTheme, $themeSlug): string {
        return trim($wpTheme('theme', 'get', $themeSlug, '--field=version')[1]);
    };
    // Renvoie la version proposée ('' si aucune) ou '#erreur' si WP-CLI échoue.
    $themeUpdateVersion = static function () use ($wpTheme, $themeSlug): string {
        [$code, $out] = $wpTheme('theme', 'list', "--name=$themeSlug", '--fields=name,update_version', '--format=json');
        $list = $code === 0 ? json_decode($out, true) : null;
        if (!is_array($list) || !isset($list[0]['name'], $list[0]['update_version']) || $list[0]['name'] !== $themeSlug) {
            return '#erreur';
        }

        return (string) $list[0]['update_version'];
    };
    $flushTheme = static function () use ($wp): void {
        $wp('eval', 'delete_site_transient( "wawp_theme_updater_check" ); delete_site_transient( "update_themes" );');
    };

    $check('publication du thème 1.1.0', $publishTheme('1.1.0'));
    $flushTheme();
    $check('mise à jour du thème 1.1.0 proposée', $themeUpdateVersion() === '1.1.0');
    [$markerCode, $marker] = $wpTheme('eval', '$t = get_site_transient( "update_themes" ); echo $t->response["' . $themeSlug . '"]["package"] ?? "absent";');
    $check('cache de WordPress : marqueur de paquet, aucun jeton', $markerCode === 0 && trim($marker) === "$endpoint/v1/theme-update/$themeSlug", $marker);
    $linesBefore = count($accessLines());
    [$code, $out] = $wpTheme('theme', 'update', $themeSlug);
    $check('mise à jour du thème 1.1.0 installée', $code === 0 && $themeVersion() === '1.1.0', $out);
    $joined = implode("\n", array_slice($accessLines(), $linesBefore));
    $check('journal : check frais du thème puis téléchargement de type theme', str_contains($joined, '"installed_themes":{"' . $themeSlug . '":"1.0.0"}') && str_contains($joined, '"slug":"' . $themeSlug . '","version":"1.1.0","type":"theme"'), $joined);
    $check('wawp-updater (plugin de test) actif pendant la mise à jour du thème', $wp('plugin', 'is-active', $slug)[0] === 0);

    $check('publication du thème 1.2.0 puis altération', $publishTheme('1.2.0', true));
    $flushTheme();
    [$code, $out] = $wpTheme('theme', 'update', $themeSlug);
    $check('thème altéré refusé', $code !== 0 && str_contains($out, 'Signature du thème WeAre[WP] invalide') && $themeVersion() === '1.1.0', $out);
    $check('thème toujours actif', trim($wp('option', 'get', 'stylesheet')[1]) === $themeSlug);

    $writeLicences(true, false);
    $flushTheme();
    $check('thème non couvert par la licence : aucune offre', $themeUpdateVersion() === '');
    $writeLicences(true);

    // e. Clé retirée de wp-config.php : aucune requête vers le serveur.
    $logs = static function () use ($accessLines): int {
        return count($accessLines());
    };
    $wp('config', 'delete', 'WAWP_LICENSE_KEY');
    $flush();
    $before = $logs();
    $check("journal d'accès non vide avant le contrôle sans clé", $before > 0);
    $check('sans clé : aucune mise à jour', $updateVersion() === '');
    $check('sans clé : aucune requête au serveur', $logs() === $before);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

echo $failures === 0 ? "\nTous les scénarios passent.\n" : "\n$failures scénario(s) en échec. Journaux : $tmp/server.log et $data/logs/\n";
exit($failures === 0 ? 0 : 1);
