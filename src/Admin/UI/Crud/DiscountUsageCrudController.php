<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\Support\AdminJsonFormatter;
use App\Entity\DiscountUsage;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

final class DiscountUsageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return DiscountUsage::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            AssociationField::new('discountCode', 'کد تخفیف'),
            AssociationField::new('user', 'کاربر'),
            AssociationField::new('order', 'سفارش')->setRequired(false),
            IntegerField::new('amountBefore', 'مبلغ قبل'),
            IntegerField::new('discountAmount', 'مبلغ تخفیف'),
            IntegerField::new('amountAfter', 'مبلغ بعد'),
            DateTimeField::new('usedAt', 'زمان استفاده'),
            TextareaField::new('metadata', 'اطلاعات')
                ->formatValue(static fn (mixed $value): string => AdminJsonFormatter::toPrettyHtml($value))
                ->renderAsHtml()
                ->hideOnIndex()
                ->hideOnForm(),
        ];
    }
}
