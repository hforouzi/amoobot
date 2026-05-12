<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\DiscountCode;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class DiscountCodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return DiscountCode::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('code', 'کد تخفیف')->setHelp('مثال: YALDA20'),
            TextField::new('title', 'عنوان')->setRequired(false),
            ChoiceField::new('type', 'نوع')
                ->setChoices([
                    'درصدی' => DiscountCode::TYPE_PERCENT,
                    'مبلغ ثابت' => DiscountCode::TYPE_FIXED,
                ])
                ->setHelp('درصد: 1 تا 100 | ثابت: بیشتر از صفر'),
            IntegerField::new('value', 'مقدار'),
            BooleanField::new('isActive', 'فعال'),
            DateTimeField::new('startsAt', 'شروع')->setRequired(false),
            DateTimeField::new('endsAt', 'پایان')->setRequired(false),
            IntegerField::new('maxUses', 'حداکثر استفاده')->setRequired(false),
            IntegerField::new('usedCount', 'تعداد استفاده شده')->setFormTypeOption('disabled', true),
            IntegerField::new('maxUsesPerUser', 'حداکثر هر کاربر')->setRequired(false),
            BooleanField::new('firstPurchaseOnly', 'فقط خرید اول'),
            ChoiceField::new('appliesTo', 'اعمال روی')
                ->setChoices([
                    'همه' => DiscountCode::APPLIES_ALL,
                    'خرید جدید' => 'new_service',
                    'تمدید' => 'renewal',
                    'حجم اضافه' => 'add_traffic',
                ]),
            AssociationField::new('plan', 'پلن')->setRequired(false),
            IntegerField::new('minAmount', 'حداقل مبلغ')->setRequired(false),
            DateTimeField::new('createdAt')->hideOnForm(),
            DateTimeField::new('updatedAt')->hideOnForm(),
        ];
    }
}
