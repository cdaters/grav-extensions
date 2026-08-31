<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use RuntimeException;
use Throwable;

final class OpenSslAeadBackend implements AeadBackendInterface
{
    public const ID = 'openssl-aes-256-gcm-v1';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function __construct(private readonly ?bool $availabilityOverride = null)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function available(): bool
    {
        if ($this->availabilityOverride !== null) {
            return $this->availabilityOverride;
        }
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            return false;
        }
        return in_array(self::CIPHER, array_map('strtolower', openssl_get_cipher_methods(true)), true);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad): array
    {
        $this->assertReady($key);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        try {
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad,
                self::TAG_BYTES
            );
            if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
                throw new RuntimeException('Jarvis could not encrypt the credential with OpenSSL.');
            }
            return [
                'nonce' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'ciphertext' => base64_encode($ciphertext),
            ];
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Jarvis could not encrypt the credential with OpenSSL.');
        } finally {
            SodiumAeadBackend::zero($iv);
            SodiumAeadBackend::zero($tag);
        }
    }

    public function decrypt(array $record, #[\SensitiveParameter] string $key, string $aad): string
    {
        $this->assertReady($key);
        $iv = $this->decode($record, 'nonce');
        $tag = $this->decode($record, 'tag');
        $ciphertext = $this->decode($record, 'ciphertext');
        try {
            if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
                throw new RuntimeException('The stored Jarvis credential authentication data is invalid.');
            }
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );
            if (!is_string($plaintext)) {
                throw new RuntimeException('The stored Jarvis credential could not be authenticated.');
            }
            return $plaintext;
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('The stored Jarvis credential could not be authenticated.');
        } finally {
            SodiumAeadBackend::zero($iv);
            SodiumAeadBackend::zero($tag);
            SodiumAeadBackend::zero($ciphertext);
        }
    }

    private function assertReady(string $key): void
    {
        if (!$this->available()) {
            throw new RuntimeException('The authenticated OpenSSL credential backend is unavailable on this host.');
        }
        if (strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('The Jarvis master key has an invalid length.');
        }
    }

    /** @param array<string, mixed> $record */
    private function decode(array $record, string $field): string
    {
        $value = $record[$field] ?? null;
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded)) {
            throw new RuntimeException('The stored Jarvis credential record is malformed.');
        }
        return $decoded;
    }
}
