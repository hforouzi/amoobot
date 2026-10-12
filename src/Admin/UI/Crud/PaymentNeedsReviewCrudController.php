<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;

/**
 * Payments the gateway reported as paid but whose amount/currency did not match (or that would pay an
 * already fulfilled order). Approve confirms and provisions; Reject closes the payment.
 */
class PaymentNeedsReviewCrudController extends PaymentCrudController
{
    protected const STATUS = PaymentStatus::NEEDS_REVIEW;

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setPageTitle(Crud::PAGE_INDEX, 'Payments: needs review')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.status = :attentionStatus')
            ->setParameter('attentionStatus', static::STATUS);
    }
}
