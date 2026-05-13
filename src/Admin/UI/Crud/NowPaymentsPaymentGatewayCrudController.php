<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
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
            ->setLabel('api_key')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('always_empty', false)
            ->setHelp('کلید API از داشبورد NOWPayments')
            ->onlyOnForms();

        $ipnSecretField = TextField::new('nowPaymentsIpnSecret')
            ->setLabel('ipn_secret')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('always_empty', false)
            ->setHelp('IPN Secret برای اعتبارسنجی وبهوک')
            ->onlyOnForms();

        return [
            FormField::addFieldset('General'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setHelp('نام نمایشی درگاه'),
            TextareaField::new('description')->hideOnIndex()->setHelp('توضیحات اختیاری'),
            BooleanField::new('isActive')->setLabel('فعال'),
            FormField::addFieldset('Credentials'),
            $apiKeyField,
            $ipnSecretField,
            FormField::addFieldset('Payment Settings'),
            TextField::new('nowPaymentsApiBaseUrl')->setLabel('api_base_url')->setHelp('آدرس API NOWPayments. پیشفرض: https://api.nowpayments.io/v1'),
            BooleanField::new('nowPaymentsSandbox')->setLabel('sandbox')->setHelp('حالت sandbox برای تست'),
            TextField::new('nowPaymentsCallbackBaseUrl')->setLabel('callback_base_url')->setHelp('آدرس پایه سایت شما، مثال: https://your-domain.com'),
            ChoiceField::new('nowPaymentsPaymentMode')->setLabel('حالت پرداخت')->setChoices([
                'صفحه پرداخت / Invoice' => 'invoice',
                'پرداخت مستقیم کیف پول / Direct Payment' => 'payment',
            ])->setHelp('حالت invoice پیش‌فرض است و صفحه پرداخت NOWPayments را نمایش می‌دهد.'),
            TextField::new('nowPaymentsPriceCurrency')->setLabel('price_currency')->setHelp('ارز قیمت (معمولاً usd)'),
            TextField::new('nowPaymentsPayCurrency')->setLabel('pay_currency')->setHelp('برای USDT TRC20 از usdttrc20 استفاده کنید. اگر TRX می‌خواهید trx بگذارید. در حالت invoice با lock=false می‌تواند خالی باشد.'),
            BooleanField::new('nowPaymentsLockPayCurrency')->setLabel('lock_pay_currency')->setHelp('در حالت invoice اگر فعال باشد pay_currency روی درگاه قفل می‌شود.'),
            ChoiceField::new('nowPaymentsAmountUnit')->setLabel('amount_unit')->setChoices([
                'toman' => 'toman',
                'rial' => 'rial',
            ])->setHelp('واحد مبالغ سفارش در سیستم شما. اگر قیمت‌ها را به تومان نگه می‌دارید `toman` را انتخاب کنید.'),
            IntegerField::new('nowPaymentsTomanPerUsd')->setLabel('toman_per_usd')->setHelp('help.nowpayments_rate')->hideOnIndex(),
            IntegerField::new('nowPaymentsIrrToUsdRate')->setLabel('irr_to_usd_rate')->setHelp('help.nowpayments_rate')->hideOnIndex(),
            TextField::new('nowPaymentsMinPriceAmountOverride')->setLabel('min_price_amount_override')->setHelp('حداقل مبلغ دلاری قیمت برای NOWPayments (اختیاری). اگر خالی باشد از min-amount خود NOWPayments استفاده می‌شود.')->hideOnIndex(),
            IntegerField::new('nowPaymentsMinOrderAmountToman')->setLabel('min_order_amount_toman')->setHelp('حداقل مبلغ سفارش به تومان برای نمایش این روش پرداخت در فروشگاه (اختیاری).')->hideOnIndex(),
            TextField::new('nowPaymentsSuccessUrl')->setLabel('success_url')->setHelp('آدرس بازگشت پس از پرداخت موفق (اختیاری)')->hideOnIndex(),
            TextField::new('nowPaymentsCancelUrl')->setLabel('cancel_url')->setHelp('آدرس بازگشت پس از لغو پرداخت (اختیاری)')->hideOnIndex(),
            TextField::new('nowPaymentsOrderDescription')->setLabel('order_description')->setHelp('توضیحات سفارش ارسالی به NOWPayments')->hideOnIndex(),
            FormField::addFieldset('Metadata'),
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
