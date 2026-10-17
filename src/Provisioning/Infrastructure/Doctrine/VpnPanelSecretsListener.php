<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Doctrine;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\PanelCredentials;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Encrypts a panel's secrets before every flush (preFlush runs for each managed panel, before change sets are
 * computed), so a typed token or password never reaches the database in clear text.
 */
#[AsEntityListener(event: Events::prePersist, method: 'encrypt', entity: VpnPanel::class)]
#[AsEntityListener(event: Events::preFlush, method: 'encrypt', entity: VpnPanel::class)]
final class VpnPanelSecretsListener
{
    public function __construct(private readonly PanelCredentials $credentials)
    {
    }

    public function encrypt(VpnPanel $panel): void
    {
        $this->credentials->encryptPending($panel);
    }
}
