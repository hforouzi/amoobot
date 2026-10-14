<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * login_throttling on the admin firewall: 5 failed attempts per 15 minutes lock the account+IP, even for the right password.
 * Each test uses its own email and client IP, so limiter state kept in the cache between runs cannot leak in.
 */
final class AdminLoginThrottlingTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254)));
        $this->email = 'throttle-'.bin2hex(random_bytes(6)).'@shop.test';

        $admin = (new AdminUser())->setEmail($this->email)->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $this->em->persist($admin);
        $this->em->flush();
    }

    public function testCorrectPasswordAfterFourFailuresStillLogsIn(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->attempt('wrong-'.$i);
            self::assertNotInstanceOf(TooManyLoginAttemptsAuthenticationException::class, $this->lastError());
        }

        $this->attempt(self::PASSWORD);

        self::assertNull($this->lastError());
        self::assertStringNotContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testFiveFailuresLockOutEvenTheCorrectPassword(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->attempt('wrong-'.$i);
        }

        $this->attempt(self::PASSWORD);

        self::assertInstanceOf(TooManyLoginAttemptsAuthenticationException::class, $this->lastError());
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function attempt(string $password): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $form = $crawler->filter('form')->form(['email' => $this->email, 'password' => $password]);
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    private function lastError(): ?\Throwable
    {
        $error = $this->client->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);

        return $error instanceof \Throwable ? $error : null;
    }
}
