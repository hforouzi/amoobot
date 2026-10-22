<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Admin\Catalog\CatalogInput;
use App\Shop\Application\PlanPriceSanityChecker;
use App\Tests\Support\IntegrationTestCase;

/**
 * The plumbing of the Mini App catalog forms: reading the form (Persian digits, separators, required numbers, ticked
 * boxes, dates), the audit's "what changed", and the price plausibility findings as data.
 */
final class CatalogInputTest extends IntegrationTestCase
{
    public function testTheFormIsReadAsTyped(): void
    {
        $input = new CatalogInput($this->em);
        $plan = new Plan();
        $errors = $input->toPlan($plan, ['title' => ' Gold ', 'durationDays' => '۳۰', 'price' => '۱۵۰٬۰۰۰', 'trafficGb' => '', 'isActive' => '1', 'ipLimit' => '2.5']);

        self::assertSame(['ipLimit' => 'admin.form.number'], $errors);
        self::assertSame(['Gold', 30, 150000, null, true, false], [$plan->getTitle(), $plan->getDurationDays(), $plan->getPrice(), $plan->getTrafficGb(), $plan->isActive(), $plan->isAllowCustomUsername()]);
        self::assertSame(['durationDays' => 'admin.form.required', 'price' => 'admin.form.required', 'inbound' => 'admin.form.choice'], $input->toPlan(new Plan(), ['title' => 'x', 'inbound' => '999999999']));

        $code = new DiscountCode();
        self::assertSame([], $input->toDiscount($code, ['code' => 'A1', 'type' => 'fixed', 'value' => '5000', 'appliesTo' => 'all', 'startsAt' => '۲۰۲۶-۰۱-۰۲T۱۰:۰۰']));
        self::assertSame('2026-01-02 10:00', $code->getStartsAt()?->format('Y-m-d H:i'));
        self::assertSame(['endsAt' => 'admin.form.date'], $input->toDiscount($code, ['code' => 'A1', 'type' => 'fixed', 'value' => '5000', 'appliesTo' => 'all', 'endsAt' => 'tomorrow']));
    }

    public function testTheAuditDiffNamesChangedFieldsAndValuesOnlyForPricesAndTheFlag(): void
    {
        self::assertSame(
            ['changed' => ['title', 'price', 'isActive'], 'values' => ['price' => [100, 120], 'isActive' => [true, false]]],
            CatalogEditor::diff(['title' => 'a', 'price' => 100, 'isActive' => true, 'ipLimit' => 2], ['title' => 'b', 'price' => 120, 'isActive' => false, 'ipLimit' => 2], ['price', 'isActive'])
        );
        self::assertSame(['changed' => []], CatalogEditor::diff(['price' => 1], ['price' => 1], ['price']));
    }

    public function testThePlausibilityFindingsMatchTheWarnings(): void
    {
        $plan = (new Plan())->setTitle('x')->setDurationDays(30)->setPrice(50)->setPricePerGb(5_000_000);
        $checker = new PlanPriceSanityChecker();

        self::assertSame([
            ['field' => 'price', 'kind' => 'low', 'value' => 50, 'min' => 5000, 'max' => 20000000],
            ['field' => 'pricePerGb', 'kind' => 'high', 'value' => 5000000, 'min' => 500, 'max' => 200000],
        ], $checker->issues($plan));
        self::assertCount(2, $checker->warnings($plan));
        self::assertStringContainsString('Price 50 IRT looks too low', $checker->warnings($plan)[0]);
    }
}
