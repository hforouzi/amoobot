<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
final class NowPaymentsPaymentGatewayCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityPermission(AdminCrudPermissions::view(self::class));
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
            ->setType(PaymentGatewayType::NOWPAYMENTS)
            ->setCurrency('IRR')
            ->setIsActive(true)
            ->setNowPaymentsSandbox(false)
            ->setNowPaymentsApiBaseUrl('https://api.nowpayments.io/v1')
            ->setNowPaymentsPaymentMode('invoice')
            ->setNowPaymentsLockPayCurrency(false)
            ->setNowPaymentsPriceCurrency('usd')
            ->setNowPaymentsPayCurrency('usdttrc20')
            ->setNowPaymentsAmountUnit('toman')
            ->setNowPaymentsOrderDescription('Amoobot VPN order');
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setType(PaymentGatewayType::NOWPAYMENTS)
                ->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof PaymentGateway) {
            $entityInstance
                ->setType(PaymentGatewayType::NOWPAYMENTS)
                ->setUpdatedAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        $apiKeyField = TextField::new('nowPaymentsApiKey')
            
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('always_empty', true)
            ->setFormTypeOption('required', false)
            ->setHelp('help.now_payments_payment_gateway.now_payments_api_key')
            ->onlyOnForms();

        $ipnSecretField = TextField::new('nowPaymentsIpnSecret')
            
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('always_empty', true)
            ->setFormTypeOption('required', false)
            ->setHelp('help.now_payments_payment_gateway.now_payments_ipn_secret')
            ->onlyOnForms();

        return [
            FormField::addFieldset('fieldset.general'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setHelp('help.now_payments_payment_gateway.title'),
            TextareaField::new('description')->hideOnIndex()->setHelp('help.now_payments_payment_gateway.description'),
            BooleanField::new('isActive'),
            FormField::addFieldset('fieldset.credentials'),
            $apiKeyField,
            $ipnSecretField,
            TextField::new('nowPaymentsApiKeyConfiguredLabel')
                ->hideOnForm(),
            TextField::new('nowPaymentsIpnSecretConfiguredLabel')
                ->hideOnForm(),
            FormField::addFieldset('fieldset.payment_settings'),
            TextField::new('nowPaymentsApiBaseUrl')->setHelp('help.now_payments_payment_gateway.now_payments_api_base_url'),
            BooleanField::new('nowPaymentsSandbox')->setHelp('help.now_payments_payment_gateway.now_payments_sandbox'),
            TextField::new('nowPaymentsCallbackBaseUrl')->setHelp('help.now_payments_payment_gateway.now_payments_callback_base_url'),
            ChoiceField::new('nowPaymentsPaymentMode')->setChoices([
                'payment_gateway.nowpayments_mode.invoice' => 'invoice',
                'payment_gateway.nowpayments_mode.payment' => 'payment',
            ])->setHelp('help.now_payments_payment_gateway.now_payments_payment_mode'),
            TextField::new('nowPaymentsPriceCurrency')->setHelp('help.now_payments_payment_gateway.now_payments_price_currency'),
            TextField::new('nowPaymentsPayCurrency')->setHelp('help.now_payments_payment_gateway.now_payments_pay_currency'),
            BooleanField::new('nowPaymentsLockPayCurrency')->setHelp('help.now_payments_payment_gateway.now_payments_lock_pay_currency'),
            ChoiceField::new('nowPaymentsAmountUnit')->setChoices([
                'payment_gateway.amount_unit.toman' => 'toman',
                'payment_gateway.amount_unit.rial' => 'rial',
            ])->setHelp('help.now_payments_payment_gateway.now_payments_amount_unit'),
            IntegerField::new('nowPaymentsTomanPerUsd')->setHelp('help.nowpayments_rate')->hideOnIndex(),
            IntegerField::new('nowPaymentsIrrToUsdRate')->setHelp('help.nowpayments_rate')->hideOnIndex(),
            TextField::new('nowPaymentsMinPriceAmountOverride')->setHelp('help.now_payments_payment_gateway.now_payments_min_price_amount_override')->hideOnIndex(),
            IntegerField::new('nowPaymentsMinOrderAmountToman')->setHelp('help.now_payments_payment_gateway.now_payments_min_order_amount_toman')->hideOnIndex(),
            TextField::new('nowPaymentsSuccessUrl')->setHelp('help.now_payments_payment_gateway.now_payments_success_url')->hideOnIndex(),
            TextField::new('nowPaymentsCancelUrl')->setHelp('help.now_payments_payment_gateway.now_payments_cancel_url')->hideOnIndex(),
            TextField::new('nowPaymentsOrderDescription')->setHelp('help.now_payments_payment_gateway.now_payments_order_description')->hideOnIndex(),
            FormField::addFieldset('fieldset.metadata'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $qb->andWhere('entity.type = :gatewayType')
            ->setParameter('gatewayType', PaymentGatewayType::NOWPAYMENTS);

        return $qb;
    }
}
