<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\BroadcastActionsController;
use App\Admin\UI\TranslatedFlashes;
use App\Admin\UI\Field\JsonField;
use App\Admin\UI\Support\PlainTextHelp;
use App\Broadcast\BroadcastAdministration;
use App\Broadcast\BroadcastFormat;
use App\Broadcast\BroadcastFormatException;
use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Security\AdminAuditLogger;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Broadcasts on the web. The text must parse in its format (BroadcastMessage::validateFormat, the shared
 * BroadcastFormat); plain text is the default, the format has help, and the form's "escape automatically" box makes
 * MarkdownV2 that does not parse plain text on save. Duplicate (a finished one as a new draft) and retry failed are
 * POST actions of BroadcastActionsController.
 */
final class BroadcastMessageCrudController extends AbstractCrudController
{
    use TranslatedFlashes;

    /** The form's unmapped "escape automatically" box. */
    public const ESCAPE_FIELD = 'escapeMarkdownV2';

    public function __construct(
        private readonly BroadcastAdministration $broadcastAdministration,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
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
        // POST forms to BroadcastActionsController with a per-broadcast CSRF token.
        $duplicate = Action::new('duplicateBroadcast', 'action.duplicate_broadcast', 'fa fa-copy')
            ->linkToUrl(fn (BroadcastMessage $broadcast): string => $this->generateUrl('admin_broadcast_duplicate', $this->actionParameters($broadcast)))
            ->renderAsForm()
            ->setCssClass('btn btn-secondary')
            ->displayIf(static fn (BroadcastMessage $broadcast): bool => BroadcastAdministration::isDuplicable($broadcast));
        $retry = Action::new('retryFailedBroadcast', 'action.retry_failed_broadcast', 'fa fa-redo')
            ->linkToUrl(fn (BroadcastMessage $broadcast): string => $this->generateUrl('admin_broadcast_retry_failed', $this->actionParameters($broadcast)))
            ->renderAsForm()
            ->setCssClass('btn btn-secondary')
            ->displayIf(static fn (BroadcastMessage $broadcast): bool => $broadcast->getFailedCount() > 0 && BroadcastMessage::STATUS_CANCELLED !== $broadcast->getStatus());
        foreach ([$duplicate, $retry] as $action) {
            $actions->add(Crud::PAGE_INDEX, $action)->add(Crud::PAGE_DETAIL, $action)
                ->setPermission($action->getAsDto()->getName(), AdminPermission::BROADCAST_SEND->value);
        }

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
                'broadcast.status.stopped' => BroadcastMessage::STATUS_STOPPED,
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
            ])
            // Shown as text: the help has tags as examples.
            ->setHelp(new PlainTextHelp('broadcast.parse_mode_help'));

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

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withEscape(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->withEscape(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * "Escape automatically": a ticked box escapes MarkdownV2 that would not parse (BroadcastFormat::canEscape) before
     * the form is validated, so it is saved and sent exactly as written.
     */
    private function withEscape(FormBuilderInterface $builder): FormBuilderInterface
    {
        $builder->add(self::ESCAPE_FIELD, CheckboxType::class, [
            'mapped' => false,
            'required' => false,
            'label' => 'broadcast.escape_markdown',
            'help' => 'broadcast.escape_markdown_help',
            'translation_domain' => 'admin',
        ]);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data) || empty($data[self::ESCAPE_FIELD]) || !is_string($data['body'] ?? null)) {
                return;
            }
            $parseMode = is_string($data['parseMode'] ?? null) ? $data['parseMode'] : null;
            if (BroadcastFormat::canEscape($data['body'], $parseMode)) {
                $data['body'] = BroadcastFormat::escapeMarkdownV2($data['body']);
                $event->setData($data);
            }
        });

        return $builder;
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
        try {
            $summary = $this->broadcastAdministration->prepare($broadcast);
        } catch (BroadcastFormatException $e) {
            return $this->formatRefused($broadcast, $e);
        }
        if (null === $summary) {
            $this->flash('danger', 'flash.broadcast.only_draft_editable');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $this->flash('success', 'flash.broadcast.recipients_prepared', ['%new%' => $summary->prepared, '%duplicates%' => $summary->duplicates]);

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function sendNow(AdminContext $context, AdminAuditLogger $auditLogger): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);
        try {
            $summary = $this->broadcastAdministration->sendNow($broadcast);
        } catch (BroadcastFormatException $e) {
            return $this->formatRefused($broadcast, $e);
        }

        if (null === $summary) {
            $this->flash('danger', 'flash.broadcast.only_draft_sendable');

            return $this->redirect($this->detailUrl($broadcast));
        }

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

        if (!$this->broadcastAdministration->cancel($broadcast)) {
            $this->flash('danger', 'flash.broadcast.not_cancellable');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $this->flash('success', 'flash.broadcast.cancelled');

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function retryFailed(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);
        $summary = $this->broadcastAdministration->retryFailed($broadcast);
        if (null === $summary) {
            $this->flash('danger', 'flash.broadcast.retry_format_blocked');

            return $this->redirect($this->detailUrl($broadcast));
        }

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

    private function formatRefused(BroadcastMessage $broadcast, BroadcastFormatException $e): RedirectResponse
    {
        $this->flash('danger', 'flash.broadcast.format_invalid', ['%problem%' => $this->translator->trans($e->problem->message, $e->problem->parameters, 'validators')]);

        return $this->redirect($this->detailUrl($broadcast));
    }

    /**
     * @return array{id: int|null, _token: string}
     */
    private function actionParameters(BroadcastMessage $broadcast): array
    {
        return [
            'id' => $broadcast->getId(),
            '_token' => $this->csrfTokenManager->getToken(BroadcastActionsController::CSRF_PREFIX.$broadcast->getId())->getValue(),
        ];
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
