<?php

declare(strict_types=1);

namespace App\Security;

use App\Admin\UI\Support\AdminJsonFormatter;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes AdminAuditLog rows from the existing action points (web controllers, EasyAdmin entity events, bot actions,
 * console commands). Details are masked with the admin display rules (AdminJsonFormatter). A failure to write is
 * logged and never breaks the action itself.
 */
final class AdminAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     * @param AdminActor|null      $actor   the bot/CLI actor; null = the logged-in web admin during a web request, else the console
     */
    public function record(string $action, ?string $targetType = null, string|int|null $targetId = null, array $details = [], ?AdminActor $actor = null): void
    {
        $inRequest = null !== $this->requestStack->getCurrentRequest();
        if (null === $actor && $inRequest) {
            $user = $this->security->getUser();
            $actor = $user instanceof AdminUser ? AdminActor::fromAdminUser($user) : null;
        }
        $source = $actor->source ?? ($inRequest ? AdminAuditLog::SOURCE_WEB : AdminAuditLog::SOURCE_CLI);
        $label = $actor->label ?? ($inRequest ? 'anonymous' : 'console');

        try {
            $this->entityManager->persist(new AdminAuditLog(
                $actor?->adminUserId,
                $label,
                $source,
                $action,
                $targetType,
                null === $targetId ? null : (string) $targetId,
                [] === $details ? null : AdminJsonFormatter::maskSecrets($details),
            ));
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Admin audit log entry could not be written.', ['action' => $action, 'target' => $targetType.'#'.$targetId, 'error' => $e->getMessage()]);
        }
    }
}
