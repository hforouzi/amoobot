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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

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
            ->setNowPaymentsPriceCurrency('usd')
            ->setNowPaymentsPayCurrency('usdttrc20')
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
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setHelp('نام نمایشی درگاه'),
            TextareaField::new('description')->hideOnIndex()->setHelp('توضیحات اختیاری'),
            BooleanField::new('isActive')->setLabel('فعال'),
            TextField::new('nowPaymentsApiKey')->setLabel('api_key')->setHelp('کلید API از داشبورد NOWPayments'),
            TextField::new('nowPaymentsIpnSecret')->setLabel('ipn_secret')->setHelp('IPN Secret برای اعتبارسنجی وبهوک'),
            TextField::new('nowPaymentsApiBaseUrl')->setLabel('api_base_url')->setHelp('آدرس API NOWPayments. پیشفرض: https://api.nowpayments.io/v1'),
            BooleanField::new('nowPaymentsSandbox')->setLabel('sandbox')->setHelp('حالت sandbox برای تست'),
            TextField::new('nowPaymentsCallbackBaseUrl')->setLabel('callback_base_url')->setHelp('آدرس پایه سایت شما، مثال: https://your-domain.com'),
            TextField::new('nowPaymentsPriceCurrency')->setLabel('price_currency')->setHelp('ارز قیمت (معمولاً usd)'),
            TextField::new('nowPaymentsPayCurrency')->setLabel('pay_currency')->setHelp('ارز پرداخت، مثال: usdttrc20، btc، eth'),
            IntegerField::new('nowPaymentsIrrToUsdRate')->setLabel('irr_to_usd_rate')->setHelp('نرخ تبدیل ریال به دلار (تعداد ریال در ازای ۱ دلار)، مثال: 600000')->hideOnIndex(),
            TextField::new('nowPaymentsSuccessUrl')->setLabel('success_url')->setHelp('آدرس بازگشت پس از پرداخت موفق (اختیاری)')->hideOnIndex(),
            TextField::new('nowPaymentsCancelUrl')->setLabel('cancel_url')->setHelp('آدرس بازگشت پس از لغو پرداخت (اختیاری)')->hideOnIndex(),
            TextField::new('nowPaymentsOrderDescription')->setLabel('order_description')->setHelp('توضیحات سفارش ارسالی به NOWPayments')->hideOnIndex(),
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
