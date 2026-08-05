<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "The Sodium extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$path = isset($argv[1]) && trim((string) $argv[1]) !== ''
    ? (string) $argv[1]
    : $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'update-signing.key';

if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

if (is_file($path)) {
    $encoded = trim((string) file_get_contents($path));
    $secretKey = base64_decode($encoded, true);

    if (!is_string($secretKey) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "The existing signing key is invalid.\n");
        exit(1);
    }
} else {
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Unable to create the signing key directory.\n");
        exit(1);
    }

    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $encoded = base64_encode($secretKey) . "\n";

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write the signing key.\n");
        exit(1);
    }

    @chmod($path, 0600);
}

$publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
fwrite(STDOUT, base64_encode($publicKey) . "\n");
sodium_memzero($secretKey);
