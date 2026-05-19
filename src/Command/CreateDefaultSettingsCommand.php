<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Setting;
use App\Shop\Application\SalesSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:settings:seed', description: 'Create default Setting rows', aliases: ['app:create-default-settings'])]
class CreateDefaultSettingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $paymentCardNumber,
        private readonly string $paymentCardHolder,
        private readonly ?string $paymentDescription,
        private readonly string $renewalCarryRemainingTraffic = 'true',
        private readonly string $renewalCarryRemainingDays = 'true',
        private readonly string $renewalExpiredStartFromNow = 'true',
        private readonly string $pricingGlobalDiscountPercent = '0',
        private readonly string $trafficAddonEnabled = 'true',
        private readonly string $trafficAddonMinGb = '1',
        private readonly string $trafficAddonMaxGb = '100',
        private readonly string $trafficAddonPricePerGb = '0',
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaults = [
            'payment.card_number' => $this->paymentCardNumber,
            'payment.card_holder' => $this->paymentCardHolder,
            'payment.description' => $this->paymentDescription ?? '',
            'service.notify.expiry_days' => '3,1',
            'service.notify.traffic_thresholds' => '80,95,100',
            'automation.sync_usage_enabled' => 'true',
            'automation.check_expiry_enabled' => 'true',
            'automation.send_notifications_enabled' => 'true',
            'automation.auto_suspend_expired_enabled' => 'false',
            'automation.auto_suspend_traffic_exhausted_enabled' => 'false',
            'automation.expire_incomplete_orders_enabled' => 'true',
            'automation.batch_limit' => '100',
            'orders.incomplete_expire_hours' => '24',
            'renewal.carry_remaining_traffic' => $this->renewalCarryRemainingTraffic,
            'renewal.carry_remaining_days' => $this->renewalCarryRemainingDays,
            'renewal.expired_start_from_now' => $this->renewalExpiredStartFromNow,
            'pricing.global_discount_percent' => $this->pricingGlobalDiscountPercent,
            'traffic_addon.enabled' => $this->trafficAddonEnabled,
            'traffic_addon.min_gb' => $this->trafficAddonMinGb,
            'traffic_addon.max_gb' => $this->trafficAddonMaxGb,
            'traffic_addon.price_per_gb' => $this->trafficAddonPricePerGb,
            SalesSettingsProvider::NEW_ORDERS_ENABLED => 'true',
            SalesSettingsProvider::RENEWALS_ENABLED => 'true',
            SalesSettingsProvider::ADD_TRAFFIC_ENABLED => 'true',
            SalesSettingsProvider::DISABLED_MESSAGE => SalesSettingsProvider::DEFAULT_DISABLED_MESSAGE,
        ];
        $types = [
            'automation.sync_usage_enabled' => 'boolean',
            'automation.check_expiry_enabled' => 'boolean',
            'automation.send_notifications_enabled' => 'boolean',
            'automation.auto_suspend_expired_enabled' => 'boolean',
            'automation.auto_suspend_traffic_exhausted_enabled' => 'boolean',
            'automation.expire_incomplete_orders_enabled' => 'boolean',
            'automation.batch_limit' => 'number',
            'orders.incomplete_expire_hours' => 'number',
            'renewal.carry_remaining_traffic' => 'boolean',
            'renewal.carry_remaining_days' => 'boolean',
            'renewal.expired_start_from_now' => 'boolean',
            'pricing.global_discount_percent' => 'number',
            'traffic_addon.enabled' => 'boolean',
            'traffic_addon.min_gb' => 'number',
            'traffic_addon.max_gb' => 'number',
            'traffic_addon.price_per_gb' => 'number',
            SalesSettingsProvider::NEW_ORDERS_ENABLED => 'boolean',
            SalesSettingsProvider::RENEWALS_ENABLED => 'boolean',
            SalesSettingsProvider::ADD_TRAFFIC_ENABLED => 'boolean',
            SalesSettingsProvider::DISABLED_MESSAGE => 'text',
        ];

        foreach ($defaults as $key => $value) {
            $existing = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);
            if ($existing instanceof Setting) {
                continue;
            }

            $setting = (new Setting())
                ->setKeyName($key)
                ->setValue($value)
                ->setType($types[$key] ?? 'string');
            $this->entityManager->persist($setting);
        }

        $this->entityManager->flush();
        $output->writeln('Default settings created.');

        return Command::SUCCESS;
    }
}
