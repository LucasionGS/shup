<?php

namespace App\Support;

use RuntimeException;

/**
 * Password-based encryption for user-protected files and pastes.
 *
 * The original scheme passed the user's password straight to openssl as the
 * 256-bit AES key (no KDF, no salt), used CBC with no MAC, and base64-encoded
 * the result before writing it to disk. That meant weak effective keys,
 * malleable ciphertexts with a padding/format oracle on the decrypt path, and
 * 33% wasted storage.
 *
 * The current format is:
 *
 *     "SHUP1" | salt(16) | iv(12) | tag(16) | ciphertext
 *
 * with the key derived via PBKDF2-SHA256. AES-256-GCM makes the ciphertext
 * authenticated, so tampering fails cleanly instead of decrypting to garbage.
 *
 * Legacy blobs (base64 of iv|ciphertext, AES-256-CBC keyed on the raw password)
 * are still readable so existing uploads keep working; callers can re-encrypt
 * opportunistically when they hold the plaintext password.
 */
class PasswordCrypto
{
    public const MAGIC = 'SHUP1';

    /** Chunked, streamable variant used for files on disk. */
    public const STREAM_MAGIC = 'SHUPS1';

    /** 1 MiB plaintext per sealed chunk. */
    private const CHUNK_BYTES = 1048576;

    private const CIPHER = 'aes-256-gcm';
    private const SALT_BYTES = 16;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const PBKDF2_ITERATIONS = 210000;
    private const LEGACY_CIPHER = 'aes-256-cbc';

    /**
     * Encrypt a string with the current scheme.
     */
    public static function encrypt(string $plaintext, string $password): string
    {
        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        $key = self::deriveKey($password, $salt);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::MAGIC . $salt . $iv . $tag . $ciphertext;
    }

    /**
     * Decrypt a blob produced by either the current or the legacy scheme.
     *
     * Returns false when the password is wrong or the data has been tampered
     * with, matching the previous openssl_decrypt() contract.
     */
    public static function decrypt(string $blob, string $password): string|false
    {
        if (self::isCurrentFormat($blob)) {
            $offset = strlen(self::MAGIC);
            $salt = substr($blob, $offset, self::SALT_BYTES);
            $offset += self::SALT_BYTES;
            $iv = substr($blob, $offset, self::IV_BYTES);
            $offset += self::IV_BYTES;
            $tag = substr($blob, $offset, self::TAG_BYTES);
            $offset += self::TAG_BYTES;
            $ciphertext = substr($blob, $offset);

            return openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                self::deriveKey($password, $salt),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        }

        return self::decryptLegacy($blob, $password);
    }

    /**
     * Encrypt a file on disk chunk by chunk.
     *
     * Each chunk is sealed independently with its own IV and tag, prefixed by a
     * 4-byte length, so neither encryption nor decryption ever needs more than
     * one chunk in memory. The chunk index is bound in as additional
     * authenticated data so chunks cannot be reordered or dropped.
     */
    public static function encryptFile(string $sourcePath, string $destinationPath, string $password): void
    {
        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            throw new RuntimeException("Unable to read $sourcePath");
        }

        $out = fopen($destinationPath, 'wb');

        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Unable to write $destinationPath");
        }

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $key = self::deriveKey($password, $salt);

            fwrite($out, self::STREAM_MAGIC);
            fwrite($out, $salt);

            $index = 0;

            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $iv = random_bytes(self::IV_BYTES);
                $tag = '';

                $ciphertext = openssl_encrypt(
                    $chunk,
                    self::CIPHER,
                    $key,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    (string) $index,
                    self::TAG_BYTES
                );

                if ($ciphertext === false) {
                    throw new RuntimeException('Encryption failed.');
                }

                fwrite($out, pack('N', strlen($ciphertext)));
                fwrite($out, $iv);
                fwrite($out, $tag);
                fwrite($out, $ciphertext);

                $index++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypt a stream produced by encryptFile() into $out.
     *
     * Falls back to the whole-blob path for files written by the older
     * non-streaming formats.
     *
     * @param resource $in
     * @param resource $out
     */
    public static function decryptStream($in, $out, string $password): void
    {
        $magic = fread($in, strlen(self::STREAM_MAGIC));

        if ($magic !== self::STREAM_MAGIC) {
            // Older format: read it whole and decrypt in one pass.
            rewind($in);
            $blob = stream_get_contents($in);
            $plaintext = self::decrypt($blob === false ? '' : $blob, $password);

            if ($plaintext !== false) {
                fwrite($out, $plaintext);
            }

            return;
        }

        $salt = fread($in, self::SALT_BYTES);

        if ($salt === false || strlen($salt) !== self::SALT_BYTES) {
            return;
        }

        $key = self::deriveKey($password, $salt);
        $index = 0;

        while (!feof($in)) {
            $header = fread($in, 4);

            if ($header === false || strlen($header) < 4) {
                break;
            }

            $length = unpack('N', $header)[1];
            $iv = fread($in, self::IV_BYTES);
            $tag = fread($in, self::TAG_BYTES);
            $ciphertext = $length > 0 ? fread($in, $length) : '';

            if ($iv === false || $tag === false || $ciphertext === false) {
                break;
            }

            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                (string) $index
            );

            if ($plaintext === false) {
                // Authentication failed: stop rather than emit garbage.
                return;
            }

            fwrite($out, $plaintext);
            $index++;
        }
    }

    public static function isCurrentFormat(string $blob): bool
    {
        return str_starts_with($blob, self::MAGIC)
            && strlen($blob) >= strlen(self::MAGIC) + self::SALT_BYTES + self::IV_BYTES + self::TAG_BYTES;
    }

    /**
     * The pre-SHUP1 format: base64 of iv|ciphertext, AES-256-CBC, password used
     * directly as the key.
     */
    private static function decryptLegacy(string $blob, string $password): string|false
    {
        $decoded = base64_decode($blob, true);

        if ($decoded === false) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::LEGACY_CIPHER);

        if (strlen($decoded) <= $ivLength) {
            return false;
        }

        return openssl_decrypt(
            substr($decoded, $ivLength),
            self::LEGACY_CIPHER,
            $password,
            OPENSSL_RAW_DATA,
            substr($decoded, 0, $ivLength)
        );
    }

    private static function deriveKey(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true);
    }
}
