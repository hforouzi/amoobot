<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure;

use App\Entity\VpnPanel;
use App\Shared\Infrastructure\SecretCipher;

/**
 * The only place a panel's secrets are read in clear text (for the request that needs them, never for display).
 * Values stored before encryption existed are still read, and are encrypted on the panel's next flush.
 */
final class PanelCredentials
{
    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    public function apiToken(VpnPanel $panel): ?string
    {
        $value = $panel->getPlainApiToken() ?? $this->reveal($panel->getStoredApiToken());

        return null === $value || '' === trim($value) ? null : trim($value);
    }

    public function password(VpnPanel $panel): ?string
    {
        $value = $panel->getPlainPassword() ?? $this->reveal($panel->getStoredPassword());

        return null === $value || '' === $value ? null : $value;
    }

    public function setApiToken(VpnPanel $panel, #[\SensitiveParameter] string $plain): void
    {
        $panel->setPlainApiToken($plain);
        $this->encryptPending($panel);
    }

    /**
     * Moves typed secrets into the encrypted columns and encrypts values left in clear text by older versions
     * (including the old config.api_token copy). Called on every flush of a panel (VpnPanelSecretsListener).
     */
    public function encryptPending(VpnPanel $panel): void
    {
        $token = $panel->getPlainApiToken() ?? $panel->getStoredApiToken();
        if (null !== $token && !SecretCipher::isEncrypted($token)) {
            $panel->storeApiToken($this->cipher->encrypt($token));
        } elseif (null !== $token && null !== ($panel->getConfig()['api_token'] ?? null)) {
            $panel->storeApiToken($token);
        }
        $panel->setPlainApiToken(null);

        $password = $panel->getPlainPassword() ?? $panel->getStoredPassword();
        if (null !== $password && !SecretCipher::isEncrypted($password)) {
            $panel->storePassword($this->cipher->encrypt($password));
        }
        $panel->setPlainPassword(null);
    }

    private function reveal(?string $stored): ?string
    {
        if (null === $stored) {
            return null;
        }

        return SecretCipher::isEncrypted($stored) ? $this->cipher->decrypt($stored) : $stored;
    }
}
