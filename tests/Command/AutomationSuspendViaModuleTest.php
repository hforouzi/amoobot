<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\AutoSuspendSummary;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SpyProductModule;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PM1c: the "suspend" job of app:automation:run runs ServiceAutoSuspendService through the 'vpn' product module
 * (runLifecycleTask('expiry'|'traffic', $limit, $dryRun)), once per enabled task. What the job does and says is pinned
 * against BASELINE, recorded by running these scenarios on develop/v3 at 9b9a165, before the change: exit code, the
 * console output, every automation_* log line (service ids replaced by the fixture's name) and each service's status.
 * The "expiry" job (ServiceExpiryChecker) does not use the module and is pinned the same way.
 */
final class AutomationSuspendViaModuleTest extends IntegrationTestCase
{
    // A fixed COLUMNS (200) for every run, so no block wraps differently on another terminal.
    use ConsoleOutputAssertions;

    private const SETTINGS = ['automation.auto_suspend_expired_enabled', 'automation.auto_suspend_traffic_exhausted_enabled', 'automation.batch_limit', 'automation.check_expiry_enabled'];

    /** Recorded on develop/v3 (9b9a165) with the same scenarios. */
    private const BASELINE = [
        'suspend' => [
            'exit' => 0,
            'display' => "\nSummary\n-------\n\n * checked: 2\n * updated: 0\n * suspended: 2\n * notified: 0\n * broadcasts: 0\n * failed: 0\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'suspended',
                'exhausted' => 'suspended',
                'healthy' => 'active',
            ],
        ],
        'suspend, dry run' => [
            'exit' => 0,
            'display' => "\nSummary\n-------\n\n * checked: 2\n * updated: 0\n * suspended: 2\n * notified: 0\n * broadcasts: 0\n * failed: 0\n * skipped: 0\n * undeliverable: 0\n\n ! [NOTE] Dry-run mode: no data was saved and no notifications were sent (counters show simulated actions).\n\n",
            'logs' => [
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'active',
                'exhausted' => 'active',
                'healthy' => 'active',
            ],
        ],
        'suspend, a panel without driver, limit 1' => [
            'exit' => 1,
            'display' => "\nSummary\n-------\n\n * checked: 2\n * updated: 0\n * suspended: 1\n * notified: 0\n * broadcasts: 0\n * failed: 1\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'error',
                    'message' => 'automation_auto_suspend_expired_failed',
                    'context' => [
                        'service_id' => 'broken',
                        'status' => 'active',
                        'panel_type' => 'pm1c_no_driver',
                        'message' => 'No VPN driver supports requested panel',
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 0,
                            'failed' => 1,
                            'skipped' => 0,
                        ],
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'active',
                'exhausted' => 'suspended',
                'broken' => 'active',
            ],
        ],
        'suspend, batch limit setting' => [
            'exit' => 1,
            'display' => "\nSummary\n-------\n\n * checked: 3\n * updated: 0\n * suspended: 2\n * notified: 0\n * broadcasts: 0\n * failed: 1\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'error',
                    'message' => 'automation_auto_suspend_expired_failed',
                    'context' => [
                        'service_id' => 'broken',
                        'status' => 'active',
                        'panel_type' => 'pm1c_no_driver',
                        'message' => 'No VPN driver supports requested panel',
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                        'summary' => [
                            'checked' => 2,
                            'suspended' => 1,
                            'failed' => 1,
                            'skipped' => 0,
                        ],
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'suspended',
                'broken' => 'active',
                'exhausted' => 'suspended',
                'healthy' => 'active',
            ],
        ],
        'suspend, only expiry enabled' => [
            'exit' => 0,
            'display' => "\nSummary\n-------\n\n * checked: 1\n * updated: 0\n * suspended: 1\n * notified: 0\n * broadcasts: 0\n * failed: 0\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                        'summary' => [
                            'checked' => 1,
                            'suspended' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_skipped_by_setting',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'suspended',
                'exhausted' => 'active',
            ],
        ],
        'suspend, both off' => [
            'exit' => 0,
            'display' => "\nSummary\n-------\n\n * checked: 0\n * updated: 0\n * suspended: 0\n * notified: 0\n * broadcasts: 0\n * failed: 0\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'info',
                    'message' => 'automation_task_skipped_by_setting',
                    'context' => [
                        'task' => 'auto_suspend_expired',
                    ],
                ],
                [
                    'level' => 'info',
                    'message' => 'automation_task_skipped_by_setting',
                    'context' => [
                        'task' => 'auto_suspend_traffic_exhausted',
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'active',
                'exhausted' => 'active',
            ],
        ],
        'expiry job' => [
            'exit' => 0,
            'display' => "\nSummary\n-------\n\n * checked: 3\n * updated: 1\n * suspended: 0\n * notified: 0\n * broadcasts: 0\n * failed: 0\n * skipped: 0\n * undeliverable: 0\n\n",
            'logs' => [
                [
                    'level' => 'info',
                    'message' => 'automation_task_completed',
                    'context' => [
                        'task' => 'check_expiry',
                        'summary' => [
                            'checked' => 3,
                            'updated' => 1,
                            'failed' => 0,
                            'skipped' => 0,
                        ],
                    ],
                ],
            ],
            'statuses' => [
                'expired' => 'expired',
                'exhausted' => 'active',
                'healthy' => 'active',
            ],
        ],
    ];

    private SpyProductModule $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = SpyProductModule::install(self::getContainer());
        $this->clearSettings();
    }

    protected function tearDown(): void
    {
        $this->clearSettings();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>, list<string>, list<array{string, int, bool}>}>
     */
    public static function scenarios(): iterable
    {
        $on = ['automation.auto_suspend_expired_enabled' => '1', 'automation.auto_suspend_traffic_exhausted_enabled' => '1'];
        $both = static fn (int $limit, bool $dryRun): array => [['expiry', $limit, $dryRun], ['traffic', $limit, $dryRun]];

        yield 'suspend' => [['--only' => 'suspend'], $on, ['expired', 'exhausted', 'healthy'], $both(100, false)];
        yield 'suspend, dry run' => [['--only' => 'suspend', '--dry-run' => true], $on, ['expired', 'exhausted', 'healthy'], $both(100, true)];
        yield 'suspend, a panel without driver, limit 1' => [['--only' => 'suspend', '--limit' => '1'], $on, ['expired', 'exhausted', 'broken'], $both(1, false)];
        yield 'suspend, batch limit setting' => [['--only' => 'suspend'], $on + ['automation.batch_limit' => '2'], ['expired', 'broken', 'exhausted', 'healthy'], $both(2, false)];
        yield 'suspend, only expiry enabled' => [['--only' => 'suspend'], ['automation.auto_suspend_expired_enabled' => '1'], ['expired', 'exhausted'], [['expiry', 100, false]]];
        yield 'suspend, both off' => [['--only' => 'suspend'], [], ['expired', 'exhausted'], []];
        yield 'expiry job' => [['--only' => 'expiry'], $on, ['expired', 'exhausted', 'healthy'], []];
    }

    /**
     * @param array<string, mixed>              $options
     * @param array<string, string>             $settings
     * @param list<string>                      $fixtures
     * @param list<array{string, int, bool}>    $moduleCalls
     */
    #[DataProvider('scenarios')]
    public function testTheJobGoesThroughTheModuleAndBehavesAsBefore(array $options, array $settings, array $fixtures, array $moduleCalls): void
    {
        foreach ($settings as $key => $value) {
            $this->em->getConnection()->executeStatement('INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, ?, NOW())', [$key, $value, 'text']);
        }
        $services = $this->createFixtures($fixtures);
        $logger = self::getContainer()->get(RecordingLogger::class);
        $logger->records = [];

        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $exit = $tester->execute($options);

        $observed = [
            'exit' => $exit,
            'display' => self::withoutTrailingSpaces($tester->getDisplay(true)),
            'logs' => $this->automationLogs($logger, $services),
            'statuses' => $this->statuses($services),
        ];

        self::assertSame($moduleCalls, array_map(static fn (array $call): array => $call['args'], $this->spy->calls), 'one module task call per enabled task, with the job\'s limit and dry-run');
        self::assertSame(array_fill(0, count($moduleCalls), 'runLifecycleTask'), $this->spy->methods());
        foreach ($this->spy->calls as $call) {
            self::assertInstanceOf(AutoSuspendSummary::class, $call['result']);
        }

        $name = (string) $this->dataName();
        self::assertArrayHasKey($name, self::BASELINE, 'baseline recorded for '.$name);
        $expected = self::BASELINE[$name];
        $expected['display'] = self::withoutTrailingSpaces($expected['display']);
        self::assertSame($expected, $observed);
    }

    /**
     * SymfonyStyle pads a block ("[NOTE] Dry-run mode: ...") with spaces to its line length, which depends on the OS
     * even at a fixed COLUMNS (Windows uses one column less). Only those trailing spaces are dropped; every word, line
     * and blank line still compares exactly.
     */
    private static function withoutTrailingSpaces(string $display): string
    {
        return (string) preg_replace('/[ \t]+$/m', '', $display);
    }

    /**
     * expired: active, ended two days ago; exhausted: active, 60 of 50 GB used; healthy: neither; broken: expired, on a
     * panel no driver supports (the job's driver lookup fails). Created in this order, so ids ascend in it.
     *
     * @param list<string> $names
     *
     * @return array<string, VpnService>
     */
    private function createFixtures(array $names): array
    {
        $user = $this->createUser();
        $inbound = $this->createTestInbound();
        $services = [];
        foreach ($names as $name) {
            $service = $this->createActiveService($user, 'broken' === $name ? $this->inboundWithoutDriver() : $inbound);
            $service->setUsername('pm1c_'.$name)->setRemoteId('pm1c_'.$name);
            if (in_array($name, ['expired', 'broken'], true)) {
                $service->setExpiresAt(new \DateTimeImmutable('-2 days'));
            }
            if ('exhausted' === $name) {
                $service->setTrafficUsedGb(60)->setTrafficUsedBytes(60 * 1073741824);
            }
            $services[$name] = $service;
        }
        $this->em->flush();
        $this->em->clear();

        return $services;
    }

    private function inboundWithoutDriver(): VpnInbound
    {
        $panel = (new VpnPanel())->setType('pm1c_no_driver')->setTitle('No driver');
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('9')->setTitle('No driver inbound');
        $this->em->persist($panel);
        $this->em->persist($inbound);
        $this->em->flush();

        return $inbound;
    }

    /**
     * @param array<string, VpnService> $services
     *
     * @return list<array{level: mixed, message: string, context: mixed}>
     */
    private function automationLogs(RecordingLogger $logger, array $services): array
    {
        $names = [];
        foreach ($services as $name => $service) {
            $names[(int) $service->getId()] = $name;
        }
        $records = array_values(array_filter($logger->records, static fn (array $r): bool => str_starts_with($r['message'], 'automation_')));

        return array_map(static function (array $r) use ($names): array {
            $context = $r['context'];
            array_walk_recursive($context, static function (mixed &$value, int|string $key) use ($names): void {
                if ('service_id' === $key && isset($names[(int) $value])) {
                    $value = $names[(int) $value];
                }
            });

            return ['level' => $r['level'], 'message' => $r['message'], 'context' => $context];
        }, $records);
    }

    /**
     * @param array<string, VpnService> $services
     *
     * @return array<string, string>
     */
    private function statuses(array $services): array
    {
        $statuses = [];
        foreach ($services as $name => $service) {
            $statuses[$name] = (string) $this->row('vpn_service', (int) $service->getId())['status'];
        }

        return $statuses;
    }

    private function clearSettings(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name IN (?, ?, ?, ?)', self::SETTINGS);
    }
}
