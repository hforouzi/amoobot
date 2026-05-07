<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\BotMessageLog;
use Doctrine\ORM\EntityManagerInterface;

class BotMessageLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(string $direction, array $payload, ?string $telegramId = null, ?string $updateType = null): void
    {
        $log = (new BotMessageLog())
            ->setDirection($direction)
            ->setPayload($payload)
            ->setTelegramId($telegramId)
            ->setUpdateType($updateType);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
