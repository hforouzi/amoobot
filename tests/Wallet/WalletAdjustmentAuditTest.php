<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\User;
use App\Security\AdminActor;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletAdministration;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * An admin's wallet adjustment and its audit entry are written in one transaction: either both exist or neither does,
 * and the same form submitted twice (even at the same moment) gives one ledger row and one audit entry.
 */
final class WalletAdjustmentAuditTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private User $customer;
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->customer = $this->createUser();
        $this->admin = (new AdminUser())->setEmail('wallet-admin@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN])->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    private function administration(): WalletAdministration
    {
        return self::getContainer()->get(WalletAdministration::class);
    }

    private function adjustmentRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'adjustment'");
    }

    private function auditRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'wallet.adjust'");
    }

    public function testAnAdjustmentAndItsAuditEntryAreWrittenTogetherOnce(): void
    {
        $key = WalletAdministration::newFormKey();
        $first = $this->administration()->adjust($this->customer, WalletAdministration::DIRECTION_CREDIT, '25000', 'Goodwill for the outage', $key, AdminActor::fromAdminUser($this->admin));
        $again = $this->administration()->adjust($this->customer, WalletAdministration::DIRECTION_CREDIT, '25000', 'Goodwill for the outage', $key, AdminActor::fromAdminUser($this->admin));

        self::assertSame($first->getId(), $again->getId());
        self::assertSame(1, $this->adjustmentRows());
        self::assertSame(1, $this->auditRows());
        $audit = $this->em->getConnection()->fetchAssociative("SELECT target_id, details FROM admin_audit_log WHERE action = 'wallet.adjust'");
        self::assertIsArray($audit);
        self::assertStringContainsString('"transaction_id":'.$first->getId(), (string) $audit['details']);
    }

    public function testWhenTheAuditEntryCannotBeWrittenTheAdjustmentIsNotMadeEither(): void
    {
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof AdminAuditLog) {
                        throw new \RuntimeException('Simulated audit failure');
                    }
                }
            }
        });

        try {
            $this->administration()->adjust($this->customer, WalletAdministration::DIRECTION_CREDIT, '25000', 'Goodwill for the outage', WalletAdministration::newFormKey(), AdminActor::fromAdminUser($this->admin));
            self::fail('the adjustment must fail with its audit entry');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated audit failure', $e->getMessage());
        }

        self::assertSame(0, $this->adjustmentRows());
        self::assertSame(0, (int) ($this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->customer->getId()]) ?: 0));
    }

    #[Group('subprocess')]
    public function testTheSameFormSubmittedTwiceAtOnceGivesOneAdjustmentAndOneAuditEntry(): void
    {
        $key = WalletAdministration::newFormKey();
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ([1, 2] as $ignored) {
            $process = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-adjust.php', (string) $this->customer->getId(), (string) $this->admin->getId(), $key, '25000'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $outputs[] = self::assertSubprocessClean($process, 'wallet-adjust.php');
        }

        self::assertSame($outputs[0], $outputs[1], 'both get the same ledger row');
        self::assertStringStartsWith('ok:', $outputs[0]);
        self::assertSame(1, $this->adjustmentRows());
        self::assertSame(1, $this->auditRows());
    }
}
