<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

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
            ->setEntityLabelInSingular('ارسال پیام همگانی')
            ->setEntityLabelInPlural('ارسال پیام همگانی');
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

        yield TextField::new('title', 'عنوان');

        yield TextareaField::new('body', 'متن پیام');

        yield ChoiceField::new('status', 'وضعیت')
            ->setChoices([
                'پیش‌نویس' => BroadcastMessage::STATUS_DRAFT,
                'زمان‌بندی‌شده' => BroadcastMessage::STATUS_SCHEDULED,
                'در حال ارسال' => BroadcastMessage::STATUS_SENDING,
                'ارسال‌شده' => BroadcastMessage::STATUS_SENT,
                'ناموفق' => BroadcastMessage::STATUS_FAILED,
                'لغو شده' => BroadcastMessage::STATUS_CANCELLED,
            ])
            ->hideOnForm();

        yield ChoiceField::new('targetType', 'مخاطبان')
            ->setChoices([
                'همه کاربران' => BroadcastMessage::TARGET_ALL,
                'کاربران فعال' => BroadcastMessage::TARGET_ACTIVE_USERS,
                'دارای سرویس فعال' => BroadcastMessage::TARGET_USERS_WITH_ACTIVE_SERVICE,
                'نزدیک انقضا' => BroadcastMessage::TARGET_USERS_WITH_EXPIRING_SERVICE,
                'کاربران انتخابی' => BroadcastMessage::TARGET_SELECTED_USERS,
                'ادمین‌ها' => BroadcastMessage::TARGET_ADMINS,
            ]);

        yield JsonField::new('targetUserIds', 'شناسه کاربران انتخابی');

        yield ChoiceField::new('parseMode', 'فرمت متن')
            ->setChoices([
                'بدون فرمت' => '',
                'HTML' => 'HTML',
                'MarkdownV2' => 'MarkdownV2',
            ]);

        yield BooleanField::new('disableWebPagePreview', 'غیرفعال‌کردن پیش‌نمایش لینک');

        yield DateTimeField::new('sendAt', 'زمان ارسال');

        yield JsonField::new('metadata', 'متادیتا');

        yield IntegerField::new('totalRecipients', 'تعداد گیرندگان')->hideOnForm();
        yield IntegerField::new('sentCount', 'ارسال‌شده')->hideOnForm();
        yield IntegerField::new('failedCount', 'ناموفق')->hideOnForm();
        yield IntegerField::new('skippedCount', 'ردشده')->hideOnForm();

        yield AssociationField::new('createdBy', 'ایجادکننده')->hideOnForm();

        yield DateTimeField::new('startedAt', 'شروع ارسال')->hideOnForm();
        yield DateTimeField::new('finishedAt', 'پایان ارسال')->hideOnForm();
        yield DateTimeField::new('createdAt', 'زمان ایجاد')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'آخرین بروزرسانی')->hideOnForm();
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
            $this->addFlash('warning', 'فقط پیام‌های پیش‌نویس قابل ویرایش هستند.');

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

        $this->addFlash(
            'success',
            sprintf('Recipients prepared: %d new, %d duplicate.', $summary->prepared, $summary->duplicates)
        );

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function sendNow(AdminContext $context, AdminAuditLogger $auditLogger): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);

        if (BroadcastMessage::STATUS_DRAFT !== $broadcast->getStatus()) {
            $this->addFlash('danger', 'Only draft broadcasts can be sent now.');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING)->setSendAt(null);

        $this->broadcastSender->prepareDeliveries($broadcast);
        $summary = $this->broadcastSender->sendPending($broadcast, 50);
        $auditLogger->record('broadcast.send', 'BroadcastMessage', $broadcast->getId(), ['target' => $broadcast->getTargetType(), 'sent' => $summary->sent, 'failed' => $summary->failed, 'pending' => $summary->pending]);

        if ($summary->pending > 0) {
            $this->addFlash('warning', 'ارسال در صف قرار گرفت. برای پردازش cron/command اجرا شود.');
        } else {
            $this->addFlash(
                'success',
                sprintf('Broadcast send batch completed. Sent: %d, failed: %d.', $summary->sent, $summary->failed)
            );
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
            $this->addFlash('danger', 'This broadcast cannot be cancelled.');

            return $this->redirect($this->detailUrl($broadcast));
        }

        $broadcast
            ->setStatus(BroadcastMessage::STATUS_CANCELLED)
            ->setFinishedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->addFlash('success', 'Broadcast cancelled.');

        return $this->redirect($this->detailUrl($broadcast));
    }

    public function retryFailed(AdminContext $context): RedirectResponse
    {
        $broadcast = $this->requireBroadcast($context);
        $summary = $this->broadcastSender->retryFailed($broadcast, 50);

        $this->addFlash(
            'success',
            sprintf('Retry batch completed. Sent: %d, failed: %d.', $summary->sent, $summary->failed)
        );

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
