<?php

declare(strict_types=1);

namespace App\Bot\Application\Admin;

use App\Broadcast\BroadcastAdministration;
use App\Entity\AdminUser;
use App\Entity\BroadcastMessage;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bot admin: broadcasts ("admin.b", BROADCAST_SEND): recent ones with delivery counts, cancel, retry failed
 * deliveries (refused while they failed because Telegram could not parse the same text), duplicate a finished one as
 * a new draft (corrected and sent from the web panel or the Mini App). Composing a broadcast stays in the web panel
 * and the Mini App. Callbacks: l, c:<id> / ck:<id>, r:<id> / rk:<id>, d:<id>.
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
        return in_array($verb, ['l', 'c', 'ck', 'r', 'rk', 'd'], true) ? [AdminPermission::BROADCAST_SEND] : [];
    }

    public function handle(AdminRequest $request): void
    {
        $id = $request->intArg(0);
        match ($request->verb) {
            'c', 'r' => $this->ask($request, $id),
            'ck' => $this->cancel($request, $id),
            'rk' => $this->retry($request, $id),
            'd' => $this->duplicate($request, $id),
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
            if ($broadcast->getFailedCount() > 0 && !$this->broadcastAdministration->retryBlocked($broadcast)) {
                $row[] = $this->screen->rawButton('🔁 #'.$broadcast->getId(), 'admin.b:r:'.$broadcast->getId());
            }
            if (BroadcastAdministration::isDuplicable($broadcast)) {
                $row[] = $this->screen->rawButton('📄 #'.$broadcast->getId(), 'admin.b:d:'.$broadcast->getId());
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
        if (!$cancel && $this->broadcastAdministration->retryBlocked($broadcast)) {
            $this->screen->notice($request, 'admin.broadcasts.retry_format_blocked', ['id' => $id], 'admin.b:l');

            return;
        }
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
        if (null === $summary) {
            $this->screen->notice($request, 'admin.broadcasts.retry_format_blocked', ['id' => $id], 'admin.b:l');

            return;
        }
        $this->screen->audit($request, 'broadcast.retry_failed', 'BroadcastMessage', $id, ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending]);
        $this->screen->notice($request, 'admin.broadcasts.retry_done', ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending], 'admin.b:l');
    }

    /** No confirmation: it only adds a draft, which nobody receives until it is prepared and sent. */
    private function duplicate(AdminRequest $request, int $id): void
    {
        $broadcast = $this->entityManager->getRepository(BroadcastMessage::class)->find($id);
        if (!$broadcast instanceof BroadcastMessage) {
            $this->screen->notice($request, 'admin.not_found', [], 'admin.b:l');

            return;
        }
        $adminUserId = $request->admin->adminUserId;
        $copy = $this->broadcastAdministration->duplicate($broadcast, null === $adminUserId ? null : $this->entityManager->find(AdminUser::class, $adminUserId));
        if (null === $copy) {
            $this->screen->notice($request, 'admin.state_changed', [], 'admin.b:l');

            return;
        }
        $this->screen->audit($request, 'broadcast.duplicate', 'BroadcastMessage', $copy->getId(), ['from' => $id, 'title' => $copy->getTitle()]);
        $this->screen->notice($request, 'admin.broadcasts.duplicated', ['id' => (int) $copy->getId(), 'title' => $copy->getTitle()], 'admin.b:l');
    }

    public function promptPermission(string $kind): ?AdminPermission
    {
        return null;
    }

    public function answer(AdminRequest $request, string $kind, string $target, string $text): void
    {
    }
}
