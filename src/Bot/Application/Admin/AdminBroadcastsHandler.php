<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Broadcast\BroadcastAdministration;
use App\Entity\BroadcastMessage;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: broadcasts ("admin.b", BROADCAST_SEND): recent ones with delivery counts, cancel, retry failed
 * deliveries. Composing a broadcast stays in the web panel. Callbacks: l, c:<id> / ck:<id>, r:<id> / rk:<id>.
 */
final class AdminBroadcastsHandler implements AdminArea
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BroadcastAdministration $broadcastAdministration,
        private readonly AdminScreen $screen,
    ) {
    }

    public function area(): string
    {
        return 'b';
    }

    public function permissions(string $verb): array
    {
        return in_array($verb, ['l', 'c', 'ck', 'r', 'rk'], true) ? [AdminPermission::BROADCAST_SEND] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            'c', 'r' => $this->ask($request, $id),
            'ck' => $this->cancel($request, $id),
            'rk' => $this->retry($request, $id),
            default => $this->list($request),
        };
    }

    private function list(AdminRequest $request): void
    {
        /** @var list<BroadcastMessage> $broadcasts */
        $broadcasts = $this->entityManager->getRepository(BroadcastMessage::class)->findBy([], ['id' => 'DESC'], AdminScreen::PAGE_SIZE);
        $lines = [$this->screen->text('admin.broadcasts.title')];
        $rows = [];
        foreach ($broadcasts as $broadcast) {
            $lines[] = $this->screen->text('admin.broadcasts.line', [
                'id' => (int) $broadcast->getId(),
                'title' => $broadcast->getTitle(),
                'status' => $broadcast->getStatus(),
                'total' => $broadcast->getTotalRecipients(),
                'sent' => $broadcast->getSentCount(),
                'failed' => $broadcast->getFailedCount(),
                'skipped' => $broadcast->getSkippedCount(),
            ]);
            $row = [];
            if (BroadcastAdministration::isCancellable($broadcast)) {
                $row[] = $this->screen->rawButton('⛔ #'.$broadcast->getId(), 'admin.b:c:'.$broadcast->getId());
            }
            if ($broadcast->getFailedCount() > 0) {
                $row[] = $this->screen->rawButton('🔁 #'.$broadcast->getId(), 'admin.b:r:'.$broadcast->getId());
            }
            if ([] !== $row) {
                $rows[] = $row;
            }
        }
        if ([] === $broadcasts) {
            $lines[] = $this->screen->text('admin.list_empty');
        }
        $lines[] = $this->screen->text('admin.broadcasts.compose_web');
        $this->screen->send($request, implode("\n\n", $lines), $this->screen->listKeyboard($rows));
    }

    private function ask(AdminRequest $request, int $id): void
    {
        $broadcast = $this->entityManager->getRepository(BroadcastMessage::class)->find($id);
        if (!$broadcast instanceof BroadcastMessage) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.b:l');

            return;
        }
        $cancel = 'c' === $request->verb;
        $this->screen->confirm($request, $this->screen->text($cancel ? 'admin.broadcasts.confirm_cancel' : 'admin.broadcasts.confirm_retry', [
            'id' => $id, 'title' => $broadcast->getTitle(), 'failed' => $broadcast->getFailedCount(),
        ]), ($cancel ? 'admin.b:ck:' : 'admin.b:rk:').$id, 'admin.b:l');
    }

    private function cancel(AdminRequest $request, int $id): void
    {
        $broadcast = $this->entityManager->getRepository(BroadcastMessage::class)->find($id);
        if (!$broadcast instanceof BroadcastMessage || !$this->broadcastAdministration->cancel($broadcast)) {
            $this->screen->notice($request, $broadcast instanceof BroadcastMessage ? 'admin.state_changed' : 'admin.not_found', [], 'admin.b:l');

            return;
        }
        $this->screen->audit($request, 'broadcast.cancel', 'BroadcastMessage', $id, ['title' => $broadcast->getTitle()]);
        $this->screen->notice($request, 'admin.done', [], 'admin.b:l');
    }

    private function retry(AdminRequest $request, int $id): void
    {
        $broadcast = $this->entityManager->getRepository(BroadcastMessage::class)->find($id);
        if (!$broadcast instanceof BroadcastMessage || $broadcast->getFailedCount() < 1) {
            $this->screen->notice($request, $broadcast instanceof BroadcastMessage ? 'admin.state_changed' : 'admin.not_found', [], 'admin.b:l');

            return;
        }
        $summary = $this->broadcastAdministration->retryFailed($broadcast);
        $this->screen->audit($request, 'broadcast.retry_failed', 'BroadcastMessage', $id, ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending]);
        $this->screen->notice($request, 'admin.broadcasts.retry_done', ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending], 'admin.b:l');
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
