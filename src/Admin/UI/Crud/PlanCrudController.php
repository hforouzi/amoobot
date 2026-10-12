<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\Plan;
use App\Shop\Application\PlanPriceSanityChecker;
use Doctrine\ORM\EntityManagerInterface;
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
    private const TOMAN_HELP = 'Toman (تومان). Not Rial, not thousands of Toman.';

    public function __construct(private readonly PlanPriceSanityChecker $priceSanityChecker)
    {
    }

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
            IntegerField::new('price')->setLabel('Price')->setHelp(self::TOMAN_HELP),
            // Read-only column. EasyAdmin only formats fields bound to a readable property, and a
            // TextField needs a string one, so it is bound to title and shows the price check instead.
            TextField::new('title', 'Unit check')
                ->hideOnForm()
                ->setMaxLength(1000)
                ->formatValue(fn (mixed $value, ?Plan $plan): string => $plan instanceof Plan ? $this->unitCheckText($plan) : ''),
            BooleanField::new('isCustomizable', 'پلن سفارشی')
                ->setHelp('help.plan_custom_pricing'),
            IntegerField::new('minTrafficGb', 'حداقل حجم (GB)')->hideOnIndex(),
            IntegerField::new('maxTrafficGb', 'حداکثر حجم (GB)')->hideOnIndex(),
            IntegerField::new('pricePerGb', 'قیمت هر گیگ')->hideOnIndex()->setHelp(self::TOMAN_HELP),
            IntegerField::new('minDurationDays', 'حداقل مدت (روز)')->hideOnIndex(),
            IntegerField::new('maxDurationDays', 'حداکثر مدت (روز)')->hideOnIndex(),
            IntegerField::new('pricePerDay', 'قیمت هر روز')->hideOnIndex()->setHelp(self::TOMAN_HELP),
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

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->flashPriceWarnings($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->flashPriceWarnings($entityInstance);
    }

    private function flashPriceWarnings(object $plan): void
    {
        if (!$plan instanceof Plan) {
            return;
        }

        foreach ($this->priceSanityChecker->warnings($plan) as $warning) {
            // EasyAdmin renders flash messages as raw HTML: escape the admin-entered title.
            $this->addFlash('warning', sprintf('Plan "%s": %s', htmlspecialchars($plan->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $warning));
        }
    }

    private function unitCheckText(Plan $plan): string
    {
        $warnings = $this->priceSanityChecker->warnings($plan);

        return [] === $warnings ? 'OK' : '⚠ check unit: '.implode(' ', $warnings);
    }
}
