<?php

declare(strict_types=1);

namespace App\Bot\Application\ServiceAction;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class ServiceActionResolver
{
    /**
     * @param iterable<ServiceActionInterface> $actions
     */
    public function __construct(
        #[TaggedIterator('app.telegram_service_action')]
        private readonly iterable $actions,
    ) {
    }

    public function dispatch(ServiceActionContext $context): bool
    {
        foreach ($this->actions as $action) {
            if (!$action->supports($context->data)) {
                continue;
            }

            $action->handle($context);

            return true;
        }

        return false;
    }
}
