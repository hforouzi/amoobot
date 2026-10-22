<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Admin\UI\DashboardController;
use App\Admin\UI\Support\AdminFormat;
use App\Entity\DiscountCode;
use App\Shop\Application\Catalog\DiscountAdminService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final class DiscountCodeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly AdminFormat $format,
        private readonly DiscountAdminService $discounts,
    ) {
    }

    /** Saving and deleting go through DiscountAdminService, as from the bot, the CLI and the Mini App. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof DiscountCode ? $this->discounts->save($entityInstance) : parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof DiscountCode ? $this->discounts->save($entityInstance) : parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $entityInstance instanceof DiscountCode ? $this->discounts->delete($entityInstance) : parent::deleteEntity($entityManager, $entityInstance);
    }

    public static function getEntityFqcn(): string
    {
        return DiscountCode::class;
    }

    /**
     * Discount usages are reached from here, not from the menu.
     */
    public function configureActions(Actions $actions): Actions
    {
        $usages = Action::new('discountUsages', 'admin.discount_usages', 'fa fa-bar-chart')
            ->linkToUrl(fn (): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setDashboard(DashboardController::class)
                ->setController(DiscountUsageCrudController::class)
                ->setAction(Action::INDEX)
                ->generateUrl())
            ->createAsGlobalAction();

        return $actions->add(Crud::PAGE_INDEX, $usages);
    }

    /**
     * List: code, value, uses, valid until, active. Title, limits, scope and validity start are on the detail page.
     */
    public function configureFields(string $pageName): iterable
    {
        $f = $this->format;

        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('code')->setHelp('help.discount_code.code')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => '<code dir="ltr">'.AdminFormat::e((string) $v).'</code>'),
            TextField::new('title')->setRequired(false)->hideOnIndex(),
            ChoiceField::new('type')
                ->setChoices([
                    'discount.type.percent' => DiscountCode::TYPE_PERCENT,
                    'discount.type.fixed' => DiscountCode::TYPE_FIXED,
                ])
                ->setHelp('help.discount_code.type')
                ->hideOnIndex(),
            IntegerField::new('value')->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v, ?DiscountCode $code): string => DiscountCode::TYPE_PERCENT === $code?->getType() ? '<bdi>'.(int) $v.'%</bdi>' : $f->money(null === $v ? null : (int) $v)),
            AdminFormat::field('uses', static fn (mixed $v, ?DiscountCode $code): string => null === $code ? '' : sprintf('<bdi>%s / %s</bdi>', number_format($code->getUsedCount()), null === $code->getMaxUses() ? '∞' : number_format($code->getMaxUses())), 'field.used_count')->onlyOnIndex(),
            DateTimeField::new('endsAt')->setRequired(false)->setTemplatePath(AdminFormat::TEMPLATE)->formatValue(static fn (mixed $v): string => null === $v ? AdminFormat::NONE : $f->expiry($v)),
            BooleanField::new('isActive'),

            // Detail page and form.
            DateTimeField::new('startsAt')->setRequired(false)->hideOnIndex(),
            IntegerField::new('maxUses')->setRequired(false)->hideOnIndex(),
            IntegerField::new('usedCount')->setFormTypeOption('disabled', true)->hideOnIndex(),
            IntegerField::new('maxUsesPerUser')->setRequired(false)->hideOnIndex(),
            BooleanField::new('firstPurchaseOnly')->hideOnIndex(),
            ChoiceField::new('appliesTo')
                ->setChoices([
                    'discount.applies_to.all' => DiscountCode::APPLIES_ALL,
                    'order.type.new_service' => 'new_service',
                    'order.type.renewal' => 'renewal',
                    'order.type.add_traffic' => 'add_traffic',
                ])
                ->hideOnIndex(),
            AssociationField::new('plan')->setRequired(false)->hideOnIndex(),
            IntegerField::new('minAmount')->setRequired(false)->hideOnIndex(),
            DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex(),
            DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex(),
        ];
    }
}
