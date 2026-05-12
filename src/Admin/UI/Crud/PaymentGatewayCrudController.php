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
            TextareaField::new('configJson')
                ->setHelp('JSON config example: {"merchant":"zibal","sandbox":true,"callback_base_url":"https://example.com"}')
                ->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}

