<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;

final class PaymentGatewayCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Action::INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('General'),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setLabel('Title')->setHelp('help.payment_gateway_store_method'),
            TextField::new('type')->setLabel('Type'),
            BooleanField::new('configured')->setLabel('Configured'),
            BooleanField::new('isActive')->setLabel('common.enabled'),
            TextField::new('currency')->setLabel('Currency'),
            TextareaField::new('description')->setLabel('Description')->hideOnIndex(),
            FormField::addPanel('Gateway Config'),
            TextareaField::new('configJson')
                ->setHelp('Gateway module config (read-only view here).')
                ->hideOnIndex(),
            FormField::addPanel('Metadata'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
