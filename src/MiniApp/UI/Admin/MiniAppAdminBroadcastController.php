<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Broadcast\BroadcastAdministration;
use App\Broadcast\BroadcastFormat;
use App\Broadcast\BroadcastFormatException;
use App\Broadcast\BroadcastFormatProblem;
use App\Broadcast\BroadcastSender;
use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminLists;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Admin\Broadcast\BroadcastForm;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Provisioning\Application\AutomationSettingsProvider;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Broadcasts in the Mini App admin (BROADCAST_SEND): the recent ones with their status, delivery counts and progress;
 * compose (the web form's fields and rules, a live preview, the character count and how many people the audience
 * reaches), a test send to the admin's own chat, prepare (the recipient list is fixed and counted), send (the admin
 * types the recipient count back, then confirms), cancel, retry the failed deliveries, and the delivery summary with
 * a sample of failures (reasons masked), duplicate a finished one as a new draft. A broadcast is edited only while
 * it is a draft; editing a prepared draft drops its recipient list. The text must parse in its format (BroadcastFormat,
 * the web's rule): the check offers MarkdownV2 escaping, and an unparsable text is not test-sent, prepared or sent.
 * Failures because Telegram could not parse the text are not retried (the broadcast stopped itself; it is duplicated
 * and corrected instead). Sending and retrying need the automation switch automation.broadcasts_enabled, because
 * app:broadcast:run delivers everything after the first batch. Sending is BroadcastSender's, paced for Telegram.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminBroadcastController extends AbstractController
{
    public const TABS = ['all', 'draft', 'sending', 'done', 'cancelled'];
    private const FAILURE_SAMPLE = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BroadcastAdministration $broadcasts,
        private readonly AutomationSettingsProvider $automation,
        private readonly TelegramApiClient $telegram,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[Route('/app/admin/broadcasts', name: 'miniapp_admin_broadcasts', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tab = in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : 'all';
        $page = AdminQuery::page($request);
        $items = $this->tab($this->entityManager->createQueryBuilder()->select('b')->from(BroadcastMessage::class, 'b'), $tab)->orderBy('b.id', 'DESC')
            ->setFirstResult($page * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1)->getQuery()->getResult();
        $counts = [];
        foreach (self::TABS as $key) {
            $counts[$key] = (int) $this->tab($this->entityManager->createQueryBuilder()->select('COUNT(b.id)')->from(BroadcastMessage::class, 'b'), $key)->getQuery()->getSingleScalarResult();
        }

        return $this->fragments->render($request, 'miniapp/admin/broadcasts.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'list' => ['items' => array_map(self::row(...), array_slice($items, 0, AdminLists::PAGE_SIZE)), 'page' => $page, 'hasNext' => count($items) > AdminLists::PAGE_SIZE],
            'paused' => !$this->automation->broadcastsEnabled(),
        ]);
    }

    #[Route('/app/admin/broadcasts/new', name: 'miniapp_admin_broadcast_new', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]
    public function new(Request $request): Response
    {
        return $this->page($request, new BroadcastMessage());
    }

    #[Route('/app/admin/broadcasts/{id}', name: 'miniapp_admin_broadcast', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        return $this->page($request, $this->broadcast($id));
    }

    #[Route('/app/api/admin/broadcasts', name: 'miniapp_admin_broadcast_create', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $broadcast = new BroadcastMessage();
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, 0, null,
            static fn (): array => BroadcastForm::apply($broadcast, $body),
            fn (): array => $this->violations($broadcast),
            static fn (): ?string => null,
            function () use ($request, $user, $broadcast): array {
                $adminUserId = $user->admin?->adminUserId;
                $broadcast->setCreatedBy(null === $adminUserId ? null : $this->entityManager->find(AdminUser::class, $adminUserId))->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->persist($broadcast);
                $this->entityManager->flush();
                $this->auditLogger->record('broadcast.create', 'BroadcastMessage', $broadcast->getId(), ['title' => $broadcast->getTitle(), 'target' => $broadcast->getTargetType()], $user->admin);

                return ['message' => $this->message($request, 'admin.broadcast.created'), 'redirect' => $this->generateUrl('miniapp_admin_broadcast', ['id' => $broadcast->getId()])];
            },
        );
    }

    /** A draft only; its prepared recipients are dropped (the text or audience may have changed). */
    #[Route('/app/api/admin/broadcasts/{id}', name: 'miniapp_admin_broadcast_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        if (!$broadcast->isEditable()) {
            return $this->conflict($request, 'broadcast_locked');
        }
        $before = BroadcastForm::values($broadcast);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $broadcast,
            static fn (): array => BroadcastForm::apply($broadcast, $body),
            fn (): array => $this->violations($broadcast),
            static fn (): ?string => null,
            function () use ($request, $user, $broadcast, $before): array {
                // Another admin may have started sending since this page was loaded.
                if (BroadcastMessage::STATUS_DRAFT !== $this->storedStatus($broadcast)) {
                    return $this->conflictAnswer($request, 'broadcast_locked');
                }
                $diff = CatalogEditor::diff($before, BroadcastForm::values($broadcast), []);
                $broadcast->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                $dropped = $this->broadcasts->dropRecipients($broadcast);
                $this->auditLogger->record('broadcast.update', 'BroadcastMessage', $broadcast->getId(), $diff + ['recipientsDropped' => $dropped], $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : ($dropped > 0 ? 'admin.broadcast.saved_prepare_again' : 'admin.broadcast.saved'))];
            },
        );
    }

    /** Live validation: the web's rules, the preview, the character count and the audience size; nothing saved. */
    #[Route('/app/api/admin/broadcasts/check', name: 'miniapp_admin_broadcast_check', defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        // A detached copy: the check never touches a stored broadcast.
        $broadcast = new BroadcastMessage();
        $errors = $this->errors($request, $broadcast, AdminForms::body($request));
        $audienceKnown = !isset($errors['targetType']) && !isset($errors['targetUserIds']) && !isset($errors['expiringDays']);

        return AdminActions::json([
            'errors' => (object) $errors,
            'warnings' => [],
            'preview' => BroadcastForm::preview($broadcast->getBody(), $broadcast->getParseMode()),
            // The one-click "escape automatically" (MarkdownV2 that does not parse), else null.
            'escape' => BroadcastFormat::canEscape($broadcast->getBody(), $broadcast->getParseMode()) ? BroadcastFormat::escapeMarkdownV2($broadcast->getBody()) : null,
            'live' => [
                'length' => BroadcastForm::length($broadcast->getBody()).' / '.BroadcastMessage::TELEGRAM_MESSAGE_LIMIT,
                'audience' => $audienceKnown ? (string) $this->broadcasts->audienceCount($broadcast) : '—',
            ],
        ]);
    }

    /** Sends the saved text to the admin's own chat only (no delivery row; the broadcast stays a draft). */
    #[Route('/app/api/admin/broadcasts/{id}/test', name: 'miniapp_admin_broadcast_test', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function testSend(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        if (!$broadcast->isEditable()) {
            return $this->conflict($request, 'broadcast_locked');
        }
        if (null !== $problem = $this->broadcasts->formatProblem($broadcast)) {
            return $this->formatInvalid($request, $problem);
        }

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $broadcast): array {
            try {
                $this->telegram->sendBroadcastMessageStrict($user->telegramUserId(), $broadcast->getBody(), $broadcast->getParseMode(), $broadcast->isDisableWebPagePreview());
            } catch (\Throwable $e) {
                return ['error' => 'test_send_failed', 'message' => $this->message($request, 'admin.broadcast.test_failed'), 'detail' => AdminText::safe($e->getMessage(), 200), 'status' => Response::HTTP_BAD_GATEWAY];
            }
            $this->auditLogger->record('broadcast.test_send', 'BroadcastMessage', $broadcast->getId(), ['title' => $broadcast->getTitle()], $user->admin);

            return ['message' => $this->message($request, 'admin.broadcast.test_sent')];
        });
    }

    #[Route('/app/api/admin/broadcasts/{id}/prepare', name: 'miniapp_admin_broadcast_prepare', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function prepare(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        if (!$broadcast->isEditable()) {
            return $this->conflict($request, 'broadcast_locked');
        }

        if (null !== $problem = $this->broadcasts->formatProblem($broadcast)) {
            return $this->formatInvalid($request, $problem);
        }

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $broadcast): array {
            try {
                $summary = $this->broadcasts->prepare($broadcast);
            } catch (BroadcastFormatException) {
                return $this->conflictAnswer($request, 'broadcast_state_changed');
            }
            if (null === $summary) {
                return $this->conflictAnswer($request, 'broadcast_locked');
            }
            $recipients = $broadcast->getTotalRecipients();
            $this->auditLogger->record('broadcast.prepare', 'BroadcastMessage', $broadcast->getId(), ['target' => $broadcast->getTargetType(), 'recipients' => $recipients], $user->admin);

            return ['message' => $this->message($request, 'admin.broadcast.prepared', ['%count%' => $recipients]), 'recipients' => $recipients];
        });
    }

    /**
     * The strong confirmation: the recipient count typed back (it must be the prepared count now), then Telegram's
     * confirm dialog showing it (token). Exactly the prepared recipients get it.
     */
    #[Route('/app/api/admin/broadcasts/{id}/send', name: 'miniapp_admin_broadcast_send', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function send(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        $refusal = match (true) {
            !$broadcast->isEditable() => 'broadcast_locked',
            !$this->automation->broadcastsEnabled() => 'broadcasts_paused',
            $broadcast->getTotalRecipients() < 1 => 'broadcast_not_prepared',
            default => null,
        };
        if (null !== $refusal) {
            return $this->conflict($request, $refusal);
        }
        if (null !== $problem = $this->broadcasts->formatProblem($broadcast)) {
            return $this->formatInvalid($request, $problem);
        }
        $recipients = $broadcast->getTotalRecipients();
        if (AdminText::integer(AdminForms::body($request)['confirmCount'] ?? null) !== $recipients) {
            return $this->editor->invalid($request, ['confirmCount' => [$this->message($request, 'admin.broadcast.count_mismatch')]]);
        }
        $confirm = $this->message($request, 'admin.broadcast.confirm.send', ['%count%' => $recipients, '%title%' => $broadcast->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $broadcast, $recipients): array {
            if (!$this->automation->broadcastsEnabled() || $broadcast->getTotalRecipients() !== $recipients) {
                return $this->conflictAnswer($request, 'broadcast_state_changed');
            }
            try {
                $summary = $this->broadcasts->sendPrepared($broadcast);
            } catch (BroadcastFormatException) {
                $summary = null;
            }
            if (null === $summary) {
                return $this->conflictAnswer($request, 'broadcast_state_changed');
            }
            $this->auditLogger->record('broadcast.send', 'BroadcastMessage', $broadcast->getId(), ['target' => $broadcast->getTargetType(), 'recipients' => $recipients, 'sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending], $user->admin);

            return ['message' => $summary->pending > 0
                ? $this->message($request, 'admin.broadcast.queued', ['%sent%' => $summary->sent, '%pending%' => $summary->pending])
                : $this->message($request, 'admin.broadcast.sent', ['%sent%' => $summary->sent, '%failed%' => $summary->failed])];
        });
    }

    #[Route('/app/api/admin/broadcasts/{id}/cancel', name: 'miniapp_admin_broadcast_cancel', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function cancel(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        $confirm = $this->message($request, 'admin.broadcast.confirm.cancel', ['%title%' => $broadcast->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $broadcast): array {
            if (!$this->broadcasts->cancel($broadcast)) {
                return $this->conflictAnswer($request, 'broadcast_not_cancellable');
            }
            $this->auditLogger->record('broadcast.cancel', 'BroadcastMessage', $broadcast->getId(), ['title' => $broadcast->getTitle()], $user->admin);

            return ['message' => $this->message($request, 'admin.broadcast.cancelled')];
        });
    }

    #[Route('/app/api/admin/broadcasts/{id}/retry', name: 'miniapp_admin_broadcast_retry', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function retry(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        if (!$this->automation->broadcastsEnabled()) {
            return $this->conflict($request, 'broadcasts_paused');
        }
        if ($this->broadcasts->retryBlocked($broadcast)) {
            return $this->conflict($request, 'broadcast_format_error');
        }
        $confirm = $this->message($request, 'admin.broadcast.confirm.retry', ['%count%' => $broadcast->getFailedCount(), '%title%' => $broadcast->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $broadcast): array {
            if ($broadcast->getFailedCount() < 1 || BroadcastMessage::STATUS_CANCELLED === $broadcast->getStatus()) {
                return $this->conflictAnswer($request, 'broadcast_nothing_to_retry');
            }
            $summary = $this->broadcasts->retryFailed($broadcast);
            if (null === $summary) {
                return $this->conflictAnswer($request, 'broadcast_format_error');
            }
            $this->auditLogger->record('broadcast.retry_failed', 'BroadcastMessage', $broadcast->getId(), ['sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending], $user->admin);

            return ['message' => $this->message($request, 'admin.broadcast.retried', ['%sent%' => $summary->sent, '%failed%' => $summary->failed])];
        });
    }

    /** A finished broadcast (sent, failed, stopped, cancelled) as a new draft with its text, format and audience. */
    #[Route('/app/api/admin/broadcasts/{id}/duplicate', name: 'miniapp_admin_broadcast_duplicate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.broadcast'], methods: ['POST'])]
    public function duplicate(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $broadcast = $this->broadcast($id);
        if (!BroadcastAdministration::isDuplicable($broadcast)) {
            return $this->conflict($request, 'broadcast_not_duplicable');
        }

        return $this->actions->run($request, $user, $id, null, function () use ($request, $user, $broadcast): array {
            $adminUserId = $user->admin?->adminUserId;
            $copy = $this->broadcasts->duplicate($broadcast, null === $adminUserId ? null : $this->entityManager->find(AdminUser::class, $adminUserId));
            if (null === $copy) {
                return $this->conflictAnswer($request, 'broadcast_not_duplicable');
            }
            $this->auditLogger->record('broadcast.duplicate', 'BroadcastMessage', $copy->getId(), ['from' => $broadcast->getId(), 'title' => $copy->getTitle()], $user->admin);

            return ['message' => $this->message($request, 'admin.broadcast.duplicated'), 'redirect' => $this->generateUrl('miniapp_admin_broadcast', ['id' => $copy->getId()])];
        });
    }

    /**
     * What a list row and the detail show: draft / prepared / scheduled / sending / sent / failed / cancelled, the counts
     * and the share of recipients done.
     *
     * @return array{broadcast: BroadcastMessage, state: string, total: int, sent: int, failed: int, skipped: int, pending: int, progress: int}
     */
    public static function row(BroadcastMessage $broadcast): array
    {
        $total = $broadcast->getTotalRecipients();
        $done = $broadcast->getSentCount() + $broadcast->getFailedCount() + $broadcast->getSkippedCount();

        return [
            'broadcast' => $broadcast,
            'state' => BroadcastMessage::STATUS_DRAFT === $broadcast->getStatus() && $total > 0 ? 'prepared' : $broadcast->getStatus(),
            'total' => $total,
            'sent' => $broadcast->getSentCount(),
            'failed' => $broadcast->getFailedCount(),
            'skipped' => $broadcast->getSkippedCount(),
            'pending' => max(0, $total - $done),
            'progress' => $total > 0 ? (int) round(100 * min($done, $total) / $total) : 0,
        ];
    }

    private function page(Request $request, BroadcastMessage $broadcast): Response
    {
        $failures = [];
        if (null !== $broadcast->getId() && $broadcast->getFailedCount() > 0) {
            /** @var list<BroadcastDelivery> $deliveries */
            $deliveries = $this->entityManager->createQueryBuilder()->select('d', 'u', 't')->from(BroadcastDelivery::class, 'd')
                ->leftJoin('d.user', 'u')->leftJoin('d.telegramAccount', 't')
                ->where('d.broadcast = :broadcast AND d.status = :failed')->setParameter('broadcast', $broadcast)->setParameter('failed', BroadcastDelivery::STATUS_FAILED)
                ->orderBy('d.id', 'DESC')->setMaxResults(self::FAILURE_SAMPLE)->getQuery()->getResult();
            foreach ($deliveries as $delivery) {
                $account = $delivery->getTelegramAccount();
                $failures[] = [
                    'name' => $delivery->getUser()?->getName(),
                    'username' => $account?->getUsername(),
                    'telegramId' => $delivery->getChatId(),
                    'reason' => AdminText::safe($delivery->getErrorMessage(), 200),
                ];
            }
        }

        return $this->fragments->render($request, 'miniapp/admin/broadcast.html.twig', [
            'row' => self::row($broadcast),
            'isNew' => null === $broadcast->getId(),
            'values' => BroadcastForm::values($broadcast),
            'preview' => BroadcastForm::preview($broadcast->getBody(), $broadcast->getParseMode()),
            'length' => BroadcastForm::length($broadcast->getBody()),
            'limit' => BroadcastMessage::TELEGRAM_MESSAGE_LIMIT,
            'targets' => BroadcastMessage::TARGET_TYPES,
            'parseModes' => BroadcastMessage::PARSE_MODES,
            'failures' => $failures,
            'cancellable' => BroadcastAdministration::isCancellable($broadcast),
            'duplicable' => BroadcastAdministration::isDuplicable($broadcast),
            'retryBlocked' => null !== $broadcast->getId() && $broadcast->getFailedCount() > 0 && $this->broadcasts->retryBlocked($broadcast),
            'stopReason' => AdminText::safe(is_string($broadcast->getMetadata()['formatError']['error'] ?? null) ? $broadcast->getMetadata()['formatError']['error'] : null, 300),
            'stopAfter' => BroadcastSender::FORMAT_ERROR_STOP_AFTER,
            'paused' => !$this->automation->broadcastsEnabled(),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, list<string>>
     */
    private function errors(Request $request, BroadcastMessage $broadcast, array $body): array
    {
        $errors = [];
        foreach (BroadcastForm::apply($broadcast, $body) as $field => $key) {
            $errors[$field] = [$this->message($request, $key)];
        }
        $this->localeSwitcher->setLocale(MiniAppLocale::fromRequest($request));

        return $errors + $this->violations($broadcast);
    }

    /**
     * The entity's rules (the web form's), by field.
     *
     * @return array<string, list<string>>
     */
    private function violations(BroadcastMessage $broadcast): array
    {
        $errors = [];
        foreach ($this->validator->validate($broadcast) as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private function tab(QueryBuilder $qb, string $tab): QueryBuilder
    {
        $statuses = match ($tab) {
            'draft' => [BroadcastMessage::STATUS_DRAFT, BroadcastMessage::STATUS_SCHEDULED],
            'sending' => [BroadcastMessage::STATUS_SENDING],
            'done' => [BroadcastMessage::STATUS_SENT, BroadcastMessage::STATUS_FAILED, BroadcastMessage::STATUS_STOPPED],
            'cancelled' => [BroadcastMessage::STATUS_CANCELLED],
            default => null,
        };

        return null === $statuses ? $qb : $qb->andWhere('b.status IN (:statuses)')->setParameter('statuses', $statuses);
    }

    /** The text does not parse in its format: the body field's error (the web's message), 422. */
    private function formatInvalid(Request $request, BroadcastFormatProblem $problem): JsonResponse
    {
        return $this->editor->invalid($request, ['body' => [$this->translator->trans($problem->message, $problem->parameters, 'validators', MiniAppLocale::fromRequest($request))]]);
    }

    private function conflict(Request $request, string $error): JsonResponse
    {
        $answer = $this->conflictAnswer($request, $error);
        unset($answer['status']);

        return AdminActions::json($answer, Response::HTTP_CONFLICT);
    }

    /**
     * @return array{error: string, message: string, status: int}
     */
    private function conflictAnswer(Request $request, string $error): array
    {
        return ['error' => $error, 'message' => $this->message($request, 'admin.broadcast.error.'.$error), 'status' => Response::HTTP_CONFLICT];
    }

    private function storedStatus(BroadcastMessage $broadcast): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM broadcast_message WHERE id = ?', [$broadcast->getId()]);
    }

    private function broadcast(int $id): BroadcastMessage
    {
        return $this->entityManager->find(BroadcastMessage::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}
