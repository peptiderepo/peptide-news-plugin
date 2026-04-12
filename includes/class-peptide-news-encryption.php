<?php
declare( strict_types=1 );
/**
 * AES-256-CBC encryption for sensitive option values (API keys).
 *
 * Encrypts data at rest in wp_options using AES-256-CBC with WordPress
 * auth salt as the key material. Transparent encrypt/decrypt API so
 * callers don't need to know the cipher details.
 *
 * Triggered by: Admin settings save (class-peptide-news-admin.php),
 *               and read by any class that retrieves API keys.
 *
 * Dependencies: OpenSSL PHP extension (standard on all modern hosts).
 *
 * @since 2.3.0
 * @see class-peptide-news-admin.php  — Encrypts keys on save via sanitize callback.
 * @see class-peptide-news-llm.php    — Decrypts OpenRouter key before API calls.
 * @see class-peptide-news-fetcher.php — Decrypts NewsAPI key before API calls.
 * @see ARCHITECTURE.md               — Security section documents encryption strategy.
 */
class Peptide_News_Encryption {

	/**
	 * Cipher algorithm used for encryption.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a plaintext string.
	 *
	 * Returns a base64-encoded string containing the IV prepended to the
	 * ciphertext. Returns the original value if OpenSSL is unavailable or
	 * encryption fails (fail-open so the plugin still works on hosts
	 * without OpenSSL, just without at-rest encryption).
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Encrypted value (base64) or original plaintext on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( empty( $plaintext ) ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return $plaintext;
		}

		$key = self::get_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv = openssl_random_pseudo_bytes( $iv_length );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		// Prepend IV to ciphertext and base64-encode for safe storage.
		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a previously encrypted string.
	 *
	 * Handles both encrypted (base64) and legacy plaintext values
	 * gracefully, so existing unencrypted keys continue to work
	 * during the migration period.
	 *
	 * @param string $encrypted The encrypted value (or legacy plaintext).
	 * @return string Decrypted plaintext.
	 */
	public static function decrypt( string $encrypted ): string {
		if ( empty( $encrypted ) ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return $encrypted;
		}

		// Detect legacy plaintext: if it doesn't look like base64, return as-is.
		// Valid encrypted values are always base64 and at least 24 chars
		// (16-byte IV + minimum 1-byte ciphertext, base64-encoded).
		if ( ! self::looks_encrypted( $encrypted ) ) {
			return $encrypted;
		}

		$raw = base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return $encrypted;
		}

		$key = self::get_key();
		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_length ) {
			// Too short to contain IV + ciphertext — treat as plaintext.
			return $encrypted;
		}

		$iv         = substr( $raw, 0, $iv_length );
		$ciphertext = substr( $raw, $iv_length );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $plaintext ) {
			// Decryption failed — likely a legacy plaintext value that
			// happened to look like base64. Return the original.
			return $encrypted;
		}

		return $plaintext;
	}

	/**
	 * Check whether a stored value looks like it was encrypted by this class.
	 *
	 * Used to distinguish encrypted values from legacy plaintext API keys
	 * during the migration period. Encrypted values are base64-encoded
	 * and at least 24 characters long (16-byte IV + ciphertext).
	 *
	 * @param string $value The stored option value.
	 * @return bool True if the value appears to be encrypted.
	 */
	public static function looks_encrypted( string $value ): bool {
		if ( strlen( $value ) < 24 ) {
			return false;
		}

		// Must be valid base64.
		if ( ! preg_match( '/^[A-Za-z0-9+\/]+=*$/', $value ) ) {
			return false;
		}

		// API keys typically start with "sk-" or similar prefixes
		// and contain characters that aren't valid base64.
		// If it starts with a known API key prefix, it's plaintext.
		$plaintext_prefixes = array( 'sk-', 'pk-', 'key-', 'Bearer ' );
		foreach ( $plaintext_prefixes as $prefix ) {
			if ( strpos( $value, $prefix ) === 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether the OpenSSL extension is available with the required cipher.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key from WordPress auth salt.
	 *
	 * Uses SHA-256 hash of the auth salt to ensure a consistent
	 * 32-byte key regardless of the salt's actual length.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_key(): string {
		// wp_salt('auth') returns the AUTH_KEY + AUTH_SALT concatenation.
		// Hash it to get a consistent 32-byte key for AES-256.
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
