<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

interface ServiceActionInterface
{
    public function supports(string $callbackData): bool;

    public function handle(ServiceActionContext $context): void;
}
