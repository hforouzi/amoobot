<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class PaymentGatewayCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            ChoiceField::new('type')->setChoices([
                'manual_card' => PaymentGatewayType::MANUAL_CARD,
                'zibal' => PaymentGatewayType::ZIBAL,
            ]),
            TextareaField::new('description')->hideOnIndex(),
            BooleanField::new('isActive'),
            BooleanField::new('isDefault'),
            IntegerField::new('sortOrder'),
            TextField::new('currency'),
            TextField::new('manualCardNumber')->setLabel('Manual Card: card_number')->hideOnIndex(),
            TextField::new('manualCardHolder')->setLabel('Manual Card: card_holder')->hideOnIndex(),
            TextField::new('manualBankName')->setLabel('Manual Card: bank_name')->hideOnIndex(),
            TextareaField::new('manualInstructions')->setLabel('Manual Card: instructions')->hideOnIndex(),
            TextField::new('zibalMerchant')->setLabel('Zibal: merchant')->hideOnIndex(),
            BooleanField::new('zibalSandbox')->setLabel('Zibal: sandbox')->hideOnIndex(),
            TextField::new('zibalCallbackBaseUrl')->setLabel('Zibal: callback_base_url')->hideOnIndex(),
            TextField::new('zibalDescription')->setLabel('Zibal: description')->hideOnIndex(),
            TextField::new('zibalMobile')->setLabel('Zibal: mobile')->hideOnIndex(),
            TextField::new('zibalAllowedCards')->setLabel('Zibal: allowedCards')->hideOnIndex(),
            TextField::new('zibalPercentMode')->setLabel('Zibal: percentMode')->hideOnIndex(),
            TextField::new('zibalFeeMode')->setLabel('Zibal: feeMode')->hideOnIndex(),
            TextField::new('zibalMultiplexingAccountNumber')->setLabel('Zibal: multiplexingAccountNumber')->hideOnIndex(),
            TextareaField::new('configJson')
                ->setHelp('Fallback raw config JSON')
                ->hideOnForm()
                ->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
