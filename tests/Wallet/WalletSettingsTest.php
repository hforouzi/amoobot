<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Admin\Application\SettingsAdministration;
use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Part 3 settings: the wallet is off by default; once on, paying with it is on unless switched off; the top-up range
 * and quick amounts are validated by the settings form.
 */
final class WalletSettingsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
    }

    private function settings(): WalletSettings
    {
        return self::getContainer()->get(WalletSettings::class);
    }

    private function administration(): SettingsAdministration
    {
        return self::getContainer()->get(SettingsAdministration::class);
    }

    public function testDefaults(): void
    {
        self::assertFalse($this->settings()->enabled(), 'off by default');
        self::assertFalse($this->settings()->paymentEnabled(), 'no wallet payment while the wallet is off');
        self::assertTrue($this->settings()->paymentSwitch(), 'the payment switch itself defaults to on');
        self::assertSame(WalletSettings::DEFAULT_TOPUP_MIN, $this->settings()->topUpMin());
        self::assertSame(WalletSettings::DEFAULT_TOPUP_MAX, $this->settings()->topUpMax());
        self::assertSame([50000, 100000, 200000, 500000], $this->settings()->quickAmounts());
    }

    public function testTheFormSavesTheWalletSettings(): void
    {
        self::assertNull($this->administration()->saveWallet([
            'wallet_enabled' => 'on',
            'wallet_topup_min' => '20000',
            'wallet_topup_max' => '300000',
            'wallet_topup_quick_amounts' => '300000, 20000,،100000',
        ]));

        self::assertTrue($this->settings()->enabled());
        self::assertFalse($this->settings()->paymentEnabled(), 'an unchecked box switches wallet payment off');
        self::assertSame(20000, $this->settings()->topUpMin());
        self::assertSame(300000, $this->settings()->topUpMax());
        self::assertSame([20000, 100000, 300000], $this->settings()->quickAmounts());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidForms(): iterable
    {
        yield 'min zero' => [['wallet_topup_min' => '0', 'wallet_topup_max' => '100', 'wallet_topup_quick_amounts' => ''], 'wallet_topup_min'];
        yield 'min not a number' => [['wallet_topup_min' => 'abc', 'wallet_topup_max' => '100', 'wallet_topup_quick_amounts' => ''], 'wallet_topup_min'];
        yield 'max below min' => [['wallet_topup_min' => '500', 'wallet_topup_max' => '100', 'wallet_topup_quick_amounts' => ''], 'wallet_topup_max'];
        yield 'negative max' => [['wallet_topup_min' => '1', 'wallet_topup_max' => '-5', 'wallet_topup_quick_amounts' => ''], 'wallet_topup_max'];
        yield 'quick amount out of range' => [['wallet_topup_min' => '1000', 'wallet_topup_max' => '5000', 'wallet_topup_quick_amounts' => '1000,9000'], 'wallet_topup_quick_amounts'];
        yield 'quick amount not a number' => [['wallet_topup_min' => '1000', 'wallet_topup_max' => '5000', 'wallet_topup_quick_amounts' => '1000,2.5'], 'wallet_topup_quick_amounts'];
    }

    /**
     * @param array<string, string> $form
     */
    #[DataProvider('invalidForms')]
    public function testAnInvalidFormSavesNothing(array $form, string $field): void
    {
        $problem = $this->administration()->saveWallet(['wallet_enabled' => 'on'] + $form);

        self::assertSame($field, $problem['field'] ?? null);
        self::assertFalse($this->settings()->enabled(), 'nothing saved');
    }
}
