<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Security;

use RuntimeException;
use Throwable;

final class SodiumAeadBackend implements AeadBackendInterface
{
    public const ID = 'sodium-xchacha20poly1305-ietf-v1';

    public function __construct(private readonly ?bool $availabilityOverride = null)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function available(): bool
    {
        return $this->availabilityOverride ?? (
            function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')
        );
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, #[\SensitiveParameter] string $key, string $aad): array
    {
        $this->assertReady($key);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
            return [
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ];
        } catch (Throwable) {
            throw new RuntimeException('Jarvis could not encrypt the credential with Sodium.');
        } finally {
            self::zero($nonce);
        }
    }

    public function decrypt(array $record, #[\SensitiveParameter] string $key, string $aad): string
    {
        $this->assertReady($key);
        $nonce = $this->decode($record, 'nonce');
        $ciphertext = $this->decode($record, 'ciphertext');
        try {
            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw new RuntimeException('The stored Jarvis credential nonce is invalid.');
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
            if (!is_string($plaintext)) {
                throw new RuntimeException('The stored Jarvis credential could not be authenticated.');
            }
            return $plaintext;
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('The stored Jarvis credential could not be authenticated.');
        } finally {
            self::zero($nonce);
            self::zero($ciphertext);
        }
    }

    private function assertReady(string $key): void
    {
        if (!$this->available()) {
            throw new RuntimeException('The Sodium credential backend is unavailable on this host.');
        }
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
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

    public static function zero(#[\SensitiveParameter] string &$value): void
    {
        if ($value !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }
}
