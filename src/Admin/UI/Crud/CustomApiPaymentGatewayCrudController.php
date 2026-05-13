<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class CustomApiPaymentGatewayCrudController extends AbstractCrudController
{
    private const CONFIG_HELP = <<<'TEXT'
JSON config example:
WARNING: Replace placeholder secrets (like CHANGE_ME) before enabling this gateway.
{
  "create": {
    "method": "POST",
    "url": "https://gateway.example.com/api/payment/create",
    "headers": {"Authorization": "Bearer {{api_key}}", "Content-Type": "application/json"},
    "body": {"amount": "{{amount}}", "order_id": "{{order_id}}", "payment_id": "{{payment_id}}", "callback_url": "{{callback_url}}", "description": "{{description}}", "currency": "{{currency}}"},
    "response_mapping": {"success": "success", "payment_url": "data.payment_url", "transaction_id": "data.transaction_id", "authority": "data.authority", "message": "message"}
  },
  "verify": {
    "method": "POST",
    "url": "https://gateway.example.com/api/payment/verify",
    "headers": {"Authorization": "Bearer {{api_key}}", "Content-Type": "application/json"},
    "body": {"transaction_id": "{{transaction_id}}", "authority": "{{authority}}", "amount": "{{amount}}", "payment_id": "{{payment_id}}"},
    "response_mapping": {"success": "success", "paid": "data.paid", "ref_id": "data.ref_id", "transaction_id": "data.transaction_id", "message": "message"}
  },
  "webhook": {
    "enabled": true,
    "secret_header": "X-Gateway-Signature",
    "secret": "CHANGE_ME",
    "payment_lookup": "transaction_id",
    "status_path": "status",
    "paid_values": ["paid", "success", "confirmed"]
  },
  "variables": {"api_key": "SECRET_VALUE"}
}
TEXT;

    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Action::INDEX, Action::DETAIL);
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new PaymentGateway())
            ->setType(PaymentGatewayType::CUSTOM_API)
            ->setCurrency('IRR')
            ->setIsActive(false)
            ->setConfig([
                'create' => [
                    'method' => 'POST',
                    'url' => '',
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => [
                        'amount' => '{{amount}}',
                        'order_id' => '{{order_id}}',
                        'payment_id' => '{{payment_id}}',
                        'callback_url' => '{{callback_url}}',
                        'description' => '{{description}}',
                        'currency' => '{{currency}}',
                    ],
                    'response_mapping' => [
                        'success' => 'success',
                        'payment_url' => 'data.payment_url',
                        'transaction_id' => 'data.transaction_id',
                        'authority' => 'data.authority',
                        'message' => 'message',
                    ],
                ],
                'verify' => [
                    'method' => 'POST',
                    'url' => '',
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => [
                        'transaction_id' => '{{transaction_id}}',
                        'authority' => '{{authority}}',
                        'amount' => '{{amount}}',
                        'payment_id' => '{{payment_id}}',
                    ],
                    'response_mapping' => [
                        'success' => 'success',
                        'paid' => 'data.paid',
                        'ref_id' => 'data.ref_id',
                        'transaction_id' => 'data.transaction_id',
                        'message' => 'message',
                    ],
                ],
                'webhook' => [
                    'enabled' => false,
                    'secret_header' => null,
                    'secret' => null,
                    'payment_lookup' => 'transaction_id',
                    'status_path' => null,
                    'paid_values' => ['paid', 'success', 'confirmed'],
                ],
                'variables' => [],
            ]);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $this->normalizeCustomApiGateway($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $this->normalizeCustomApiGateway($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addFieldset('fieldset.general'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextareaField::new('description')->hideOnIndex(),
            BooleanField::new('configured')->setLabel('configured')->hideOnForm(),
            BooleanField::new('isActive')->setLabel('enabled'),
            TextField::new('currency'),
            TextareaField::new('configJson')
                ->setLabel('config_json')
                ->setHelp(self::CONFIG_HELP)
                ->hideOnIndex(),
            FormField::addFieldset('fieldset.metadata'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $qb->andWhere('entity.type = :gatewayType')
            ->setParameter('gatewayType', PaymentGatewayType::CUSTOM_API);

        return $qb;
    }

    private function normalizeCustomApiGateway(PaymentGateway $gateway): void
    {
        $gateway
            ->setType(PaymentGatewayType::CUSTOM_API)
            ->setUpdatedAt(new \DateTimeImmutable());

        $config = $gateway->getConfig();
        if (!is_array($config)) {
            return;
        }

        $webhook = is_array($config['webhook'] ?? null) ? $config['webhook'] : [];
        $webhookEnabled = true === ($webhook['enabled'] ?? false);
        if (!$webhookEnabled || !$gateway->isActive()) {
            return;
        }

        $secret = strtoupper(trim((string) ($webhook['secret'] ?? '')));
        if ('' === $secret || 'CHANGE_ME' === $secret) {
            $gateway->setIsActive(false);
        }
    }
}
