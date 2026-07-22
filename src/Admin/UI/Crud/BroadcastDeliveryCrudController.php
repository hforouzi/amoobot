<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\BroadcastDelivery;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RequestStack;

final class BroadcastDeliveryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return BroadcastDelivery::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['id' => 'DESC'])
            ->setEntityLabelInSingular('Broadcast Delivery')
            ->setEntityLabelInPlural('Broadcast Deliveries');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('broadcast'),
            AssociationField::new('user'),
            AssociationField::new('telegramAccount'),
            TextField::new('chatId'),
            TextField::new('status'),
            TextField::new('telegramMessageId')->hideOnIndex(),
            TextareaField::new('errorMessage')->hideOnIndex(),
            DateTimeField::new('sentAt'),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $queryBuilder = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $broadcastId = (int) $this->requestStack->getCurrentRequest()?->query->get('broadcastId', 0);
        if ($broadcastId > 0) {
            $queryBuilder
                ->andWhere('entity.broadcast = :broadcastId')
                ->setParameter('broadcastId', $broadcastId);
        }

        return $queryBuilder;
    }
}
