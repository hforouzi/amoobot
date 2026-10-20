<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts secrets stored in the database (panel API tokens, panel passwords) with libsodium's secretbox
 * (XSalsa20-Poly1305), keyed from APP_SECRET. Stored form: "enc:v1:" + base64(nonce . ciphertext).
 *
 * Changing APP_SECRET makes stored secrets unreadable: they must then be entered again (the same is true for the
 * order-derived client UUIDs, which are also keyed from APP_SECRET).
 */
final class SecretCipher
{
    public const PREFIX = 'enc:v1:';
    private const KEY_CONTEXT = 'amoobot-db-secrets-v1';

    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] #[\SensitiveParameter] string $appSecret)
    {
        if ('' === $appSecret) {
            throw new \LogicException('APP_SECRET is empty: secrets cannot be encrypted.');
        }
        $this->key = sodium_crypto_generichash(self::KEY_CONTEXT.$appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function isEncrypted(?string $stored): bool
    {
        return null !== $stored && str_starts_with($stored, self::PREFIX);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * @throws \RuntimeException when the value is not ours or APP_SECRET changed (the message never holds the value)
     */
    public function decrypt(string $stored): string
    {
        if (!self::isEncrypted($stored)) {
            throw new \RuntimeException('Stored secret is not encrypted.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored secret is corrupted.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Stored secret cannot be decrypted (was APP_SECRET changed? Enter the secret again).');
        }

        return $plain;
    }
}
