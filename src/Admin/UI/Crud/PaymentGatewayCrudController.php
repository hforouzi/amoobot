<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Entity\PaymentGateway;
use App\Payment\Domain\PaymentGatewayType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final class PaymentGatewayCrudController extends AbstractCrudController
{
    /** Maps gateway type → per-type CRUD controller FQCN */
    private const TYPE_CONTROLLERS = [
        PaymentGatewayType::MANUAL_CARD   => ManualCardPaymentGatewayCrudController::class,
        PaymentGatewayType::ZIBAL         => ZibalPaymentGatewayCrudController::class,
        PaymentGatewayType::NOWPAYMENTS   => NowPaymentsPaymentGatewayCrudController::class,
        PaymentGatewayType::CUSTOM_API    => CustomApiPaymentGatewayCrudController::class,
    ];

    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PaymentGateway::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.payment_gateways')
            ->setEntityLabelInPlural('admin.payment_gateways');
    }

    public function configureActions(Actions $actions): Actions
    {
        $configure = Action::new('configure', 'Configure', 'fa fa-cog')
            ->linkToUrl(function (PaymentGateway $gateway): string {
                $controller = self::TYPE_CONTROLLERS[$gateway->getType()] ?? null;
                if ($controller === null) {
                    return '';
                }

                return $this->adminUrlGenerator
                    ->setController($controller)
                    ->setAction(Action::EDIT)
                    ->setEntityId($gateway->getId())
                    ->generateUrl();
            })
            ->addCssClass('btn btn-sm btn-secondary');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Action::INDEX, Action::DETAIL)
            ->add(Action::INDEX, $configure);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('title')->setLabel('Title'),
            TextField::new('type')->setLabel('Type'),
            BooleanField::new('configured')->setLabel('Configured'),
            BooleanField::new('isActive')->setLabel('common.enabled'),
            TextField::new('currency')->setLabel('Currency'),
            DateTimeField::new('updatedAt')->setLabel('common.updated_at')->hideOnForm(),
        ];
    }
}
