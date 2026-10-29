<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Entity\Reseller;
use App\Reseller\Application\ResellerCustomers;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerMiniAppFixtures;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/**
 * L6: a customer label never blocks deleting anything (Version20261031090000: every key of the label tables is ON
 * DELETE CASCADE). A labelled service, the user owning it and a reseller with labels can be deleted, taking only the
 * label rows with them; whether they may be deleted at all is still decided by the other keys (a reseller with orders
 * cannot be).
 */
final class ResellerCustomerDeletionTest extends IntegrationTestCase
{
    use ResellerMiniAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResellerMiniApp();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testEveryKeyOfTheLabelTablesCascades(): void
    {
        $rules = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT CONCAT(TABLE_NAME, '.', REFERENCED_TABLE_NAME), DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('reseller_customer', 'reseller_customer_service') ORDER BY 1",
        );

        self::assertSame(['reseller_customer.reseller' => 'CASCADE', 'reseller_customer_service.reseller_customer' => 'CASCADE', 'reseller_customer_service.vpn_service' => 'CASCADE'], $rules);
    }

    public function testDeletingALabelledServiceTakesOnlyItsAssignment(): void
    {
        $label = $this->labelled((int) $this->sold->getId());

        $this->em->getConnection()->executeStatement('DELETE FROM vpn_service WHERE id = ?', [$this->sold->getId()]);

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer WHERE id = '.$label), 'the label stays');
    }

    public function testDeletingTheUserOwningALabelledServiceIsNotBlocked(): void
    {
        $owner = $this->createUser();
        // The service now belongs to someone else (as after a claim); deleting that user deletes his services.
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET user_id = ? WHERE id = ?', [$owner->getId(), $this->sold->getId()]);
        $label = $this->labelled((int) $this->sold->getId());

        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$owner->getId()]);

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM vpn_service WHERE id = '.(int) $this->sold->getId()));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer WHERE id = '.$label));
    }

    public function testAResellerWithLabelsCanBeDeletedButHardDeleteRulesStay(): void
    {
        $user = $this->createUser();
        $newcomer = new Reseller($user);
        $this->em->persist($newcomer);
        $this->em->flush();
        $customers = self::getContainer()->get(ResellerCustomers::class);
        $customers->create($newcomer, ['name' => 'Ali']);
        $customers->create($newcomer, ['name' => 'Sara']);
        $mine = $this->labelled((int) $this->sold->getId());

        $this->em->getConnection()->executeStatement('DELETE FROM reseller WHERE id = ?', [$newcomer->getId()]);
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer WHERE reseller_id = '.(int) $newcomer->getId()));

        // The reseller with orders and sold services: still refused by those keys, his labels untouched.
        try {
            $this->em->getConnection()->executeStatement('DELETE FROM reseller WHERE id = ?', [$this->reseller->getId()]);
            self::fail('a reseller with orders was deleted');
        } catch (ForeignKeyConstraintViolationException) {
        }
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer WHERE id = '.$mine));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service'));
    }

    /** A label of the reseller with this (sold) service assigned to it; returns the label id. */
    private function labelled(int $serviceId): int
    {
        $customers = self::getContainer()->get(ResellerCustomers::class);
        $label = $customers->create($this->reseller, ['name' => 'Customer '.$serviceId]);
        self::assertTrue($customers->assign($this->reseller, $serviceId, (int) $label->getId()));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_customer_service WHERE service_id = '.$serviceId));

        return (int) $label->getId();
    }
}
