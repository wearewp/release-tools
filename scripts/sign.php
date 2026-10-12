<?php
/**
 * Signe un paquet : Ed25519 détachée sur le SHA-384 binaire du zip, encodée en base64, dans <zip>.sig.
 * La clé privée (64 octets en base64) est lue dans la variable d'environnement SIGNING_KEY et n'est jamais affichée.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$zip = $argv[1] ?? '';
if ($zip === '' || !is_file($zip)) {
    $fail('Paquet introuvable.');
}

$encoded = getenv('SIGNING_KEY');
if (!is_string($encoded) || trim($encoded) === '') {
    $fail('Variable SIGNING_KEY absente.');
}
$secret = base64_decode(trim((string) $encoded), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $fail('SIGNING_KEY invalide (64 octets en base64 attendus).');
}

$digest = hash_file('sha384', $zip, true);
if ($digest === false) {
    $fail('Lecture du paquet impossible.');
}

$signature = sodium_crypto_sign_detached((string) $digest, (string) $secret);
$public = sodium_crypto_sign_publickey_from_secretkey((string) $secret);
sodium_memzero($secret);

if (!sodium_crypto_sign_verify_detached($signature, (string) $digest, $public)) {
    $fail('Auto-vérification de la signature en échec.');
}
if (file_put_contents($zip . '.sig', base64_encode($signature) . "\n") === false) {
    $fail('Écriture de la signature impossible.');
}

echo 'Signature écrite : ' . basename($zip) . ".sig\n";
echo 'Clé publique utilisée : ' . base64_encode($public) . "\n";
