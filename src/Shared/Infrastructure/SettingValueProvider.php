<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

class SettingValueProvider
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(string $key, ?string $fallback = null): ?string
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);

        if (!$setting instanceof Setting || null === $setting->getValue() || '' === trim($setting->getValue())) {
            return $fallback;
        }

        return $setting->getValue();
    }
}
