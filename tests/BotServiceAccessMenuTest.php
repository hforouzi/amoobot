<?php

declare(strict_types=1);

use App\Bot\Application\BotContentRegistry;
use App\Bot\Application\BotTextResolver;
use App\Bot\Application\ServiceAction\RefreshServiceAction;
use App\Bot\Application\ServiceAction\ResendConfigAction;
use App\Bot\Application\ServiceAction\ServiceActionContext;
use App\Bot\Application\ServiceAction\SubscriptionLinkAction;
use App\Bot\Application\ServiceManagementService;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Entity\Setting;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shared\Infrastructure\SettingValueProvider;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\NativeQuery;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

require dirname(__DIR__).'/vendor/autoload.php';

final class BotServiceAccessMenuTest
{
    public function run(): void
    {
        $this->testServiceDetailKeyboardContainsAccessMenuCallbacks();
        $this->testDeletedServiceKeyboardHidesInvalidAccessActions();
        $this->testRenewAndAddTrafficVisibilityRemainConfigurable();
        $this->testAccessPlaceholderCallbacksAreRoutedSafely();
        $this->testUnauthorizedUserIsRejectedByPlaceholderHandler();
        $this->testRefreshCallbackUsesExistingRefreshBehavior();
        $this->testUserServiceDetailDoesNotSendAccessMessages();
    }

    private function testServiceDetailKeyboardContainsAccessMenuCallbacks(): void
    {
        $callbacks = $this->callbacks($this->keyboard()->userServiceDetail(42));

        $this->assertSame([
            'service_subscription:42',
            'service_configs:42',
            'service_qr_menu:42',
            'service_refresh:42',
            'service_renew:42',
            'service_add_traffic_order:42',
            'my_services',
        ], $callbacks, 'service detail keyboard callbacks match the access menu layout');
    }

    private function testDeletedServiceKeyboardHidesInvalidAccessActions(): void
    {
        $callbacks = $this->callbacks($this->keyboard()->userServiceDetail(42, false, false, false));

        $this->assertSame(['my_services'], $callbacks, 'deleted service keyboard hides invalid service actions');
    }

    private function testRenewAndAddTrafficVisibilityRemainConfigurable(): void
    {
        $callbacks = $this->callbacks($this->keyboard()->userServiceDetail(42, true, false));
        $this->assertContains('service_renew:42', $callbacks, 'renew remains visible when enabled');
        $this->assertNotContains('service_add_traffic_order:42', $callbacks, 'add traffic hides when disabled');

        $callbacks = $this->callbacks($this->keyboard()->userServiceDetail(42, false, true));
        $this->assertNotContains('service_renew:42', $callbacks, 'renew hides when disabled');
        $this->assertContains('service_add_traffic_order:42', $callbacks, 'add traffic remains visible when enabled');
    }

    private function testAccessPlaceholderCallbacksAreRoutedSafely(): void
    {
        $serviceManagement = new RecordingServiceManagementService();
        $context = $this->context('service_subscription:42');
        (new SubscriptionLinkAction($serviceManagement))->handle($context);

        $this->assertSame([
            ['placeholder', 42, 'subscription', 'callback-1'],
        ], $serviceManagement->calls, 'subscription callback routes to placeholder delivery');

        $serviceManagement = new RecordingServiceManagementService();
        (new ResendConfigAction($serviceManagement))->handle($this->context('service_configs:43'));
        $this->assertSame([
            ['placeholder', 43, 'configs', 'callback-1'],
        ], $serviceManagement->calls, 'configs callback routes to placeholder delivery');

        $serviceManagement = new RecordingServiceManagementService();
        (new SubscriptionLinkAction($serviceManagement))->handle($this->context('service_qr_menu:44'));
        $this->assertSame([
            ['placeholder', 44, 'qr', 'callback-1'],
        ], $serviceManagement->calls, 'QR callback routes to placeholder delivery');
    }

    private function testUnauthorizedUserIsRejectedByPlaceholderHandler(): void
    {
        $owner = $this->user(10);
        $actor = $this->account(20, 'actor-20');
        $service = $this->service(42, $owner, VpnServiceStatus::ACTIVE);
        $telegram = new RecordingTelegramApiClient();
        $serviceManagement = $this->serviceManagementWith([
            VpnService::class => [42 => $service],
            Setting::class => [],
        ], $telegram);

        $serviceManagement->showServiceAccessPlaceholder($actor, 42, 'chat-1', 'callback-1', 'subscription');

        $this->assertSame([
            ['answerCallbackQuery', 'callback-1', 'دسترسی غیرمجاز.', true],
        ], $telegram->calls, 'new access callbacks reject another user service');
    }

    private function testRefreshCallbackUsesExistingRefreshBehavior(): void
    {
        $serviceManagement = new RecordingServiceManagementService();
        (new RefreshServiceAction($serviceManagement))->handle($this->context('service_refresh:42'));

        $this->assertSame([
            ['sync', 42, false, 'callback-1', 'refresh'],
        ], $serviceManagement->calls, 'refresh callback uses existing usage sync behavior');
    }

    private function testUserServiceDetailDoesNotSendAccessMessages(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Bot/Application/ServiceManagementService.php');
        $start = strpos($source, 'private function sendUserServiceDetail');
        $end = strpos($source, 'private function sendAccessMessages');
        $method = substr($source, $start, $end - $start);

        $this->assertFalse(str_contains($method, 'ensureFreshConfigBeforeDelivery'), 'service_view no longer refreshes access config for detail display');
        $this->assertFalse(str_contains($method, 'sendAccessMessages'), 'service_view no longer sends subscription/config messages');
        $this->assertFalse(str_contains($method, 'collectConfigLinks'), 'service_view no longer collects individual config links');
    }

    private function keyboard(): TelegramKeyboardFactory
    {
        $entityManager = new InMemoryEntityManager([Setting::class => []]);
        $settingValueProvider = new SettingValueProvider($entityManager);
        $resolver = new BotTextResolver($entityManager, new KeyTranslator(), new BotContentRegistry(), $settingValueProvider, new NullLogger());

        return new TelegramKeyboardFactory($resolver, new SalesSettingsProvider($settingValueProvider));
    }

    /**
     * @param array<class-string, array<int, object>> $data
     */
    private function serviceManagementWith(array $data, RecordingTelegramApiClient $telegram): ServiceManagementService
    {
        $reflection = new ReflectionClass(ServiceManagementService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $this->setPrivate($service, 'entityManager', new InMemoryEntityManager($data));
        $this->setPrivate($service, 'telegramApiClient', $telegram);

        return $service;
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }

    private function service(int $id, User $user, string $status): VpnService
    {
        $service = (new VpnService())->setUser($user)->setStatus($status);
        $this->setPrivate($service, 'id', $id);

        return $service;
    }

    private function user(int $id): User
    {
        $user = new User();
        $this->setPrivate($user, 'id', $id);

        return $user;
    }

    private function account(int $userId, string $telegramId): TelegramAccount
    {
        return (new TelegramAccount())->setUser($this->user($userId))->setTelegramId($telegramId);
    }

    private function context(string $data): ServiceActionContext
    {
        return new ServiceActionContext($this->account(10, 'actor-10'), 'actor-10', 'chat-1', 'callback-1', $data, false);
    }

    /**
     * @return list<string>
     */
    private function callbacks(array $keyboard): array
    {
        $callbacks = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $callbacks[] = $button['callback_data'];
            }
        }

        return $callbacks;
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed: '.$message);
        }
    }

    private function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                "Assertion failed: %s\nExpected: %s\nActual: %s",
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    private function assertContains(string $needle, array $haystack, string $message): void
    {
        $this->assertTrue(in_array($needle, $haystack, true), $message);
    }

    private function assertNotContains(string $needle, array $haystack, string $message): void
    {
        $this->assertFalse(in_array($needle, $haystack, true), $message);
    }
}

final class KeyTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return 'fa';
    }
}

final class RecordingTelegramApiClient extends App\Bot\Infrastructure\TelegramApiClient
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $this->calls[] = ['answerCallbackQuery', $callbackQueryId, $text, $showAlert];
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->calls[] = ['sendMessage', $chatId, $text, $replyMarkup];
    }

    public function sendHtmlMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->calls[] = ['sendHtmlMessage', $chatId, $text, $replyMarkup];
    }
}

final class RecordingServiceManagementService extends ServiceManagementService
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function showServiceAccessPlaceholder(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, string $action): void
    {
        $this->calls[] = ['placeholder', $serviceId, $action, $callbackId];
    }

    public function syncServiceUsage(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId, bool $adminMode, ?string $accessAction = null): void
    {
        $this->calls[] = ['sync', $serviceId, $adminMode, $callbackId, $accessAction];
    }

    public function sendSubscription(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->calls[] = ['unexpected_subscription_delivery', $serviceId];
    }

    public function sendSubscriptionQr(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->calls[] = ['unexpected_qr_delivery', $serviceId];
    }

    public function sendConfigLinks(TelegramAccount $account, int $serviceId, string $chatId, string $callbackId): void
    {
        $this->calls[] = ['unexpected_config_delivery', $serviceId];
    }
}

final class InMemoryEntityRepository extends EntityRepository
{
    /**
     * @param array<int, object> $items
     */
    public function __construct(EntityManagerInterface $entityManager, string $className, private readonly array $items)
    {
        parent::__construct($entityManager, new ClassMetadata($className));
    }

    public function find(mixed $id, LockMode|int|null $lockMode = null, int|null $lockVersion = null): ?object
    {
        return $this->items[(int) $id] ?? null;
    }

    public function findOneBy(array $criteria, array|null $orderBy = null): ?object
    {
        return null;
    }
}

final class InMemoryEntityManager implements EntityManagerInterface
{
    /**
     * @param array<class-string, array<int, object>> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function getRepository(string $className): EntityRepository
    {
        return new InMemoryEntityRepository($this, $className, $this->data[$className] ?? []);
    }

    public function find(string $className, mixed $id, LockMode|int|null $lockMode = null, int|null $lockVersion = null): object|null
    {
        return $this->getRepository($className)->find($id, $lockMode, $lockVersion);
    }

    public function persist(object $object): void {}
    public function remove(object $object): void {}
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object, LockMode|int|null $lockMode = null): void {}
    public function flush(): void {}
    public function getClassMetadata(string $className): ClassMetadata { return new ClassMetadata($className); }
    public function initializeObject(object $obj): void {}
    public function isUninitializedObject(mixed $value): bool { return false; }
    public function contains(object $object): bool { return false; }
    public function getCache(): Cache|null { throw new BadMethodCallException(__METHOD__); }
    public function getConnection(): Connection { throw new BadMethodCallException(__METHOD__); }
    public function getMetadataFactory(): ClassMetadataFactory { throw new BadMethodCallException(__METHOD__); }
    public function getExpressionBuilder(): Expr { throw new BadMethodCallException(__METHOD__); }
    public function beginTransaction(): void {}
    public function wrapInTransaction(callable $func): mixed { return $func($this); }
    public function commit(): void {}
    public function rollback(): void {}
    public function createQuery(string $dql = ''): Query { throw new BadMethodCallException(__METHOD__); }
    public function createNativeQuery(string $sql, ResultSetMapping $rsm): NativeQuery { throw new BadMethodCallException(__METHOD__); }
    public function createQueryBuilder(): QueryBuilder { throw new BadMethodCallException(__METHOD__); }
    public function getReference(string $entityName, mixed $id): object|null { return $this->find($entityName, $id); }
    public function close(): void {}
    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void {}
    public function getEventManager(): EventManager { throw new BadMethodCallException(__METHOD__); }
    public function getConfiguration(): Configuration { throw new BadMethodCallException(__METHOD__); }
    public function isOpen(): bool { return true; }
    public function getUnitOfWork(): UnitOfWork { throw new BadMethodCallException(__METHOD__); }
    public function newHydrator(string|int $hydrationMode): AbstractHydrator { throw new BadMethodCallException(__METHOD__); }
    public function getProxyFactory(): ProxyFactory { throw new BadMethodCallException(__METHOD__); }
    public function getFilters(): FilterCollection { throw new BadMethodCallException(__METHOD__); }
    public function isFiltersStateClean(): bool { return true; }
    public function hasFilters(): bool { return false; }
}

(new BotServiceAccessMenuTest())->run();
echo "BotServiceAccessMenuTest passed\n";
