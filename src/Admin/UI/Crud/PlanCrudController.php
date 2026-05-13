<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\Plan;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Plan::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title'),
            TextareaField::new('description')->hideOnIndex(),
            IntegerField::new('durationDays'),
            IntegerField::new('trafficGb'),
            IntegerField::new('ipLimit')
                ->setHelp('Maximum simultaneous IPs/devices. Empty means panel/default/unlimited.'),
            IntegerField::new('price')->setLabel('Price'),
            BooleanField::new('isCustomizable', 'پلن سفارشی')
                ->setHelp('help.plan_custom_pricing'),
            IntegerField::new('minTrafficGb', 'حداقل حجم (GB)')->hideOnIndex(),
            IntegerField::new('maxTrafficGb', 'حداکثر حجم (GB)')->hideOnIndex(),
            IntegerField::new('pricePerGb', 'قیمت هر گیگ')->hideOnIndex(),
            IntegerField::new('minDurationDays', 'حداقل مدت (روز)')->hideOnIndex(),
            IntegerField::new('maxDurationDays', 'حداکثر مدت (روز)')->hideOnIndex(),
            IntegerField::new('pricePerDay', 'قیمت هر روز')->hideOnIndex(),
            BooleanField::new('allowCustomUsername', 'اجازه نام کاربری دلخواه')->hideOnIndex(),
            BooleanField::new('isUnlimitedDuration', 'مدت نامحدود')
                ->setHelp('اگر فعال باشد انقضا نامحدود است و در خرید، مدت روز پرسیده نمی‌شود.')
                ->hideOnIndex(),
            AssociationField::new('inbound', 'اینباند / سرور'),
            BooleanField::new('isActive'),
            DateTimeField::new('createdAt')->setLabel('common.created_at')->hideOnForm(),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
