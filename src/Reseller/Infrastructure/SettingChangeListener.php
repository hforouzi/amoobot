<?php

declare(strict_types=1);

namespace App\Reseller\Infrastructure;

use App\Entity\Setting;
use App\Reseller\Application\ResellerSettings;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * A setting was saved or removed: ResellerSettings forgets the answers it remembered for this request, so the change
 * counts at once (the settings page, a test that switches claim links on).
 */
#[AsEntityListener(event: Events::postPersist, method: 'changed', entity: Setting::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'changed', entity: Setting::class)]
#[AsEntityListener(event: Events::postRemove, method: 'changed', entity: Setting::class)]
final class SettingChangeListener
{
    public function __construct(private readonly ResellerSettings $settings)
    {
    }

    public function changed(Setting $setting): void
    {
        $this->settings->reset();
    }
}
