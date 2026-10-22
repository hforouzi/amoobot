<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Entity;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\AfterEntityUpdatedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Audit of the EasyAdmin create / edit / delete forms for the entities below: "<name>.create|update|delete" (an admin
 * user switched off is "admin_user.disable"). An update records the names of the changed fields, never their values.
 */
final class AdminAuditEntitySubscriber implements EventSubscriberInterface
{
    private const AUDITED = [
        Entity\Plan::class => 'plan',
        Entity\TrialPlan::class => 'trial_plan',
        Entity\DiscountCode::class => 'discount_code',
        Entity\PaymentGateway::class => 'payment_gateway',
        Entity\StorePaymentMethod::class => 'store_payment_method',
        Entity\VpnPanel::class => 'vpn_panel',
        Entity\VpnInbound::class => 'vpn_inbound',
        Entity\Setting::class => 'setting',
        Entity\RequiredChannel::class => 'required_channel',
        Entity\Plugin::class => 'plugin',
        Entity\AdminUser::class => 'admin_user',
        Entity\VpnService::class => 'service',
        Entity\Order::class => 'order',
        Entity\Payment::class => 'payment',
        Entity\AppDownload::class => 'app_download',
        Entity\ClientApp::class => 'client_app',
    ];

    /** @var \WeakMap<object, list<string>> changed fields, between the before/after update events */
    private \WeakMap $changes;
    /** @var \WeakMap<object, string> id of an entity being deleted */
    private \WeakMap $deletedIds;

    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->changes = new \WeakMap();
        $this->deletedIds = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AfterEntityPersistedEvent::class => 'onPersisted',
            BeforeEntityUpdatedEvent::class => 'beforeUpdated',
            AfterEntityUpdatedEvent::class => 'onUpdated',
            BeforeEntityDeletedEvent::class => 'beforeDeleted',
            AfterEntityDeletedEvent::class => 'onDeleted',
        ];
    }

    public function onPersisted(AfterEntityPersistedEvent $event): void
    {
        $this->record($event->getEntityInstance(), 'create');
    }

    public function beforeUpdated(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (null !== $this->name($entity)) {
            $this->changes[$entity] = $this->changedFields($entity);
        }
    }

    public function onUpdated(AfterEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        $changed = $this->changes[$entity] ?? [];
        $operation = $entity instanceof Entity\AdminUser && in_array('isActive', $changed, true)
            ? ($entity->isActive() ? 'enable' : 'disable')
            : 'update';
        $this->record($entity, $operation, ['changed' => $changed]);
    }

    public function beforeDeleted(BeforeEntityDeletedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        if (null !== $this->name($entity)) {
            $this->deletedIds[$entity] = (string) $this->id($entity);
        }
    }

    public function onDeleted(AfterEntityDeletedEvent $event): void
    {
        $entity = $event->getEntityInstance();
        $this->record($entity, 'delete', ['label' => mb_substr((string) ($entity instanceof \Stringable ? $entity : ''), 0, 190)], $this->deletedIds[$entity] ?? null);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(object $entity, string $operation, array $details = [], ?string $id = null): void
    {
        $name = $this->name($entity);
        if (null === $name) {
            return;
        }
        $this->auditLogger->record($name.'.'.$operation, (new \ReflectionClass($entity))->getShortName(), $id ?? $this->id($entity), $details);
    }

    private function name(object $entity): ?string
    {
        foreach (self::AUDITED as $class => $name) {
            if ($entity instanceof $class) {
                return $name;
            }
        }

        return null;
    }

    private function id(object $entity): ?string
    {
        $identifier = $this->entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $id = reset($identifier);

        return false === $id || null === $id ? null : (string) $id;
    }

    /**
     * @return list<string>
     */
    private function changedFields(object $entity): array
    {
        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($entity);
        $changed = [];
        foreach ([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()] as $field) {
            if ('updatedAt' === $field || !array_key_exists($field, $original)) {
                continue;
            }
            $before = $original[$field];
            $after = $metadata->getFieldValue($entity, $field);
            $differs = is_object($before) && !$before instanceof \DateTimeInterface ? $before !== $after : $before != $after;
            if ($differs) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
