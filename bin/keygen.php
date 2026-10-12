<?php
/**
 * Génère les deux paires de clés Ed25519 (active et secours). N'écrit rien sur disque.
 * À lancer uniquement dans le terminal de Thierry, jamais dans une session automatisée.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pairs = [];
foreach (['ACTIVE', 'SECOURS'] as $role) {
    $keypair = sodium_crypto_sign_keypair();
    $pairs[$role] = [
        'secret' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        'public' => base64_encode(sodium_crypto_sign_publickey($keypair)),
    ];
    sodium_memzero($keypair);
}

$active = $pairs['ACTIVE'];
$backup = $pairs['SECOURS'];

echo "=== Clé ACTIVE ===\n";
echo "Privée (secret d'organisation GitHub SIGNING_KEY, et copie dans le gestionnaire de mots de passe) :\n";
echo $active['secret'] . "\n";
echo "Publique :\n";
echo $active['public'] . "\n\n";
echo "=== Clé de SECOURS ===\n";
echo "Privée (gestionnaire de mots de passe UNIQUEMENT, jamais sur GitHub ni sur un serveur) :\n";
echo $backup['secret'] . "\n";
echo "Publique :\n";
echo $backup['public'] . "\n\n";
echo "=== Contenu de ~/updates-data/signing.pub sur o2switch ===\n";
echo "# active\n{$active['public']}\n# secours\n{$backup['public']}\n\n";
echo "=== Ligne à placer dans wawp-updater/src/class-config.php ===\n";
echo "const PUBLIC_KEYS = array( '{$active['public']}', '{$backup['public']}' );\n\n";
echo "Rien n'a été écrit sur disque. Effacez ce terminal après avoir copié les valeurs.\n";
