<?php

declare(strict_types=1);

namespace App\Admin\UI\Crud;

use App\Payment\Domain\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Verified payments whose service could not be created/renewed. "Retry provisioning" runs fulfilment
 * again without re-verifying the payment.
 */
class PaymentProvisioningFailedCrudController extends PaymentNeedsReviewCrudController
{
    protected const STATUS = PaymentStatus::PROVISIONING_FAILED;

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setPageTitle(Crud::PAGE_INDEX, 'Payments: provisioning failed');
    }
}
