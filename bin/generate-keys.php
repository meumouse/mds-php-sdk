<?php
/**
 * Generate an ed25519 key pair for MDS response signing.
 *
 * Usage:  php bin/generate-keys.php
 *
 * Output:
 *   - MDS_SIGNING_PRIVATE_KEY : base64 of the 64-byte libsodium secret key.
 *                               Set this in the mds-api environment (kept secret).
 *   - MDS_SIGNING_PUBLIC_KEY  : base64 of the 32-byte public key.
 *                               Set in mds-api AND embed it in each product as
 *                               the `public_key` SDK config value.
 *
 * The API imports the secret key as a JWK (seed = first 32 bytes, x = last 32
 * bytes) and signs with ed25519; the SDK verifies with the public key via
 * libsodium. Same raw key material on both sides — no PEM/DER juggling.
 *
 * @package MeuMouse\MDS\SDK
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the command line.\n" );
	exit( 1 );
}

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "ext-sodium is required.\n" );
	exit( 1 );
}

$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $pair ); // 64 bytes (seed || public).
$public = sodium_crypto_sign_publickey( $pair ); // 32 bytes.

echo "# Add to mds-api environment (.env):\n";
echo 'MDS_SIGNING_ENABLED=true' . "\n";
echo 'MDS_SIGNING_PRIVATE_KEY=' . base64_encode( $secret ) . "\n";
echo 'MDS_SIGNING_PUBLIC_KEY=' . base64_encode( $public ) . "\n";
echo "\n# Embed in each product SDK config as 'public_key':\n";
echo base64_encode( $public ) . "\n";
