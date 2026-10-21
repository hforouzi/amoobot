<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\TranslatedFlashes;
use App\Admin\UI\Field\JsonField;
use App\Broadcast\BroadcastSender;
use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class BroadcastMessageCrudController extends AbstractCrudController
{
    use TranslatedFlashes;

    public function __construct(
        private readonly BroadcastSender $broadcastSender,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BroadcastMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['id' => 'DESC'])
            ->setEntityLabelInSingular('entity.broadcast_message.singular')
            ->setEntityLabelInPlural('admin.broadcasts');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(
                Crud::PAGE_INDEX,
                Action::EDIT,
                static fn (Action $action): Action => $action->displayIf(
                    static fn (BroadcastMessage $broadcast): bool => $broadcast->isEditable()
                )
            )
            ->update(
                Crud::PAGE_DETAIL,
                Action::EDIT,
                static fn (Action $action): Action => $action->displayIf(
                    static fn (BroadcastMessage $broadcast): bool => $broadcast->isEditable()
                )
            )
            ->update(
                Crud::PAGE_INDEX,
                Action::DELETE,
                static fn (Action $action): Action => $action->displayIf(
                    static fn (BroadcastMessage $broadcast): bool => $broadcast->isEditable()
                )
            )
            ->update(
                Crud::PAGE_DETAIL,
                Action::DELETE,
                static fn (Action $action): Action => $action->displayIf(
                    static fn (BroadcastMessage $broadcast): bool => $broadcast->isEditable()
                )
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('title');

        yield TextareaField::new('body');

        yield ChoiceField::new('status')
            ->setChoices([
                'broadcast.status.draft' => BroadcastMessage::STATUS_DRAFT,
                'broadcast.status.scheduled' => BroadcastMessage::STATUS_SCHEDULED,
                'broadcast.status.sending' => BroadcastMessage::STATUS_SENDING,
                'broadcast.status.sent' => BroadcastMessage::STATUS_SENT,
                'broadcast.status.failed' => BroadcastMessage::STATUS_FAILED,
                'broadcast.status.cancelled' => BroadcastMessage::STATUS_CANCELLED,
            ])
            ->hideOnForm();

        yield ChoiceField::new('targetType')
            ->setChoices([
                'broadcast.target.all' => BroadcastMessage::TARGET_ALL,
                'broadcast.target.active_users' => BroadcastMessage::TARGET_ACTIVE_USERS,
                'broadcast.target.users_with_active_service' => BroadcastMessage::TARGET_USERS_WITH_ACTIVE_SERVICE,
                'broadcast.target.users_with_expiring_service' => BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE,
                'broadcast.target.selected_users' => BroadcastMessage::TARGET_SELECTED_USERS,
                'broadcast.target.admins' => BroadcastMessage::TARGET_ADMINS,
            ]);

        yield JsonField::new('targetUserIds');

        yield ChoiceField::new('parseMode')
            ->setChoices([
                'bot_text.parse_mode.plain' => '',
                'bot_text.parse_mode.html' => 'HTML',
                'bot_text.parse_mode.markdown_v2' => 'MarkdownV2',
            ]);

        yield BooleanField::new('disableWebPagePreview');

        yield DateTimeField::new('sendAt');

        yield JsonField::new('metadata');

        yield IntegerField::new('totalRecipients')->hideOnForm();
        yield IntegerField::new('sentCount')->hideOnForm();
        yield IntegerField::new('failedCount')->hideOnForm();
        yield IntegerField::new('skippedCount')->hideOnForm();

        yield AssociationField::new('createdBy')->hideOnForm();

        yield DateTimeField::new('startedAt')->hideOnForm();
        yield DateTimeField::new('finishedAt')->hideOnForm();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof BroadcastMessage) {
            $user = $this->getUser();

            if ($user instanceof AdminUser) {
                $entityInstance->setCreatedBy($user);
            }

            $this->normalizeBeforeSave($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if (!$entityInstance instanceof BroadcastMessage) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        if (!$entityInstance->isEditable()) {
            $this->flash('warning', 'flash.broadcast.only_draft_editable');

            return;
        }

        $this->normalizeBeforeSave($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function preview(AdminContext $context): Response
    {
        $broadcast = $this->getBroadcast($context);

        if (!$broadcast instanceof BroadcastMessage) {
            throw $this->createNotFoundException();
        }

        return new Response(sprintf(
            '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>%s</title><body style="font-family:tahoma,arial,sans-serif;max-width:760px;margin:32px auto;line-height:1.8"><h1>%s</h1><pre style="white-space:pre-wrap;border:1px solid #ddd;padding:16px;border-radius:6px">%s</pre><p>Target: %s | Parse mode: %s</p><p><a href="%s">Back</a></p></body></html>',
            htmlspecialchars($broadcast->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($broadcast->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($broadcast->getBody(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($broadcast->getTargetType(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($broadcast->getParseMode() ?? 'none', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->detailUrl($broadcast), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ));
    }

    public function prepareRecipients(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);
        $summary = $this->broadcastSender->prepareDeliveries($broadcast);

        $this->flash('success', 'flash.broadcast.recipients_prepared', ['%new%' => $summary->prepared, '%duplicates%' => $summary->duplicates]);

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function sendNow(AdminContext $context, AdminAuditLogger $auditLogger): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);

        if (BroadcastMessage::STATUS_DRAFT !== $broadcast->getStatus()) {
            $this->flash('danger', 'flash.broadcast.only_draft_sendable');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING)->setSendAt(null);

        $this->broadcastSender->prepareDeliveries($broadcast);
        $summary = $this->broadcastSender->sendPending($broadcast, 50);
        $auditLogger->record('broadcast.send', 'BroadcastMessage', $broadcast->getId(), ['target' => $broadcast->getTargetType(), 'sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending]);

        if ($summary->pending > 0) {
            $this->flash('warning', 'flash.broadcast.queued');
        } else {
            $this->flash('success', 'flash.broadcast.batch_sent', ['%sent%' => $summary->sent, '%failed%' => $summary->failed]);
        }

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function cancelBroadcast(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);

        if (!in_array($broadcast->getStatus(), [
            BroadcastMessage::STATUS_DRAFT,
            BroadcastMessage::STATUS_SCHEDULED,
            BroadcastMessage::STATUS_SENDING,
        ], true)) {
            $this->flash('danger', 'flash.broadcast.not_cancellable');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $broadcast
            ->setStatus(BroadcastMessage::STATUS_CANCELLED)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->flash('success', 'flash.broadcast.cancelled');

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function retryFailed(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);
        $summary = $this->broadcastSender->retryFailed($broadcast, 50);

        $this->flash('success', 'flash.broadcast.retry_sent', ['%sent%' => $summary->sent, '%failed%' => $summary->failed]);

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function viewDeliveries(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);

        $url = $this->adminUrlGenerator
            ->unsetAll()
            ->setController(BroadcastDeliveryCrudController::class)
            ->setAction(Action::INDEX)
            ->set('broadcastId', (string) $broadcast->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    private function normalizeBeforeSave(BroadcastMessage $broadcast): void
    {
        $broadcast->setUpdatedAt(new \DateTimeImmutable());

        if ('' === $broadcast->getParseMode()) {
            $broadcast->setParseMode(null);
        }

        if (
            BroadcastMessage::STATUS_DRAFT === $broadcast->getStatus()
            && $broadcast->getSendAt() instanceof \DateTimeImmutable
        ) {
            $broadcast->setStatus(BroadcastMessage::STATUS_SCHEDULED);
        }
    }

    private function getBroadcast(AdminContext $context): ?BroadcastMessage
    {
        $broadcast = $context->getEntity()->getInstance();

        return $broadcast instanceof BroadcastMessage ? $broadcast : null;
    }

    private function requireBroadcast(AdminContext $context): BroadcastMessage
    {
        $broadcast = $this->getBroadcast($context);

        if (!$broadcast instanceof BroadcastMessage) {
            throw $this->createNotFoundException();
        }

        return $broadcast;
    }

    private function detailUrl(BroadcastMessage $broadcast): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($broadcast->getId())
            ->generateUrl();
    }

}
