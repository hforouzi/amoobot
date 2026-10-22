<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Tests\Support\BotTranscript;
use PHPUnit\Framework\TestCase;

/**
 * The transcript normaliser names ids by where they appear, not by value: on a fresh database the first plan, service,
 * draft, order and payment are all id 1, and the snapshots must read the same as with any other ids.
 */
final class BotTranscriptTest extends TestCase
{
    private const IDS = [
        'PLAN' => [1 => 'PLAN'],
        'SERVICE' => [1 => 'SERVICE'],
        'DRAFT' => [1 => 'DRAFT'],
        'ORDER' => [1 => 'ORDER'],
        'PAYMENT' => [1 => 'PAYMENT'],
        'PAYMENT_METHOD' => [1 => 'CARD_METHOD', 2 => 'ZIBAL_METHOD'],
    ];

    public function testCollidingIdsAreNamedByTheirCallbackRoute(): void
    {
        $lines = BotTranscript::lines([self::message('شناسه سرویس: 1', [
            ['service_view:1', 'select_plan:1'],
            ['add_traffic_confirm:1', 'discount_enter:1', 'draft_cancel:1'],
            ['order_summary:1', 'select_store_payment_method:1:1', 'select_store_payment_method:1:2'],
            ['payment_check:1', 'renewal_confirm:1', 'buy_service'],
        ])], [], self::IDS);

        self::assertSame([implode("\n", [
            'sendMessage chat=42',
            '  text: شناسه سرویس: SERVICE',
            '  [inline_keyboard] b -> service_view:SERVICE | b -> select_plan:PLAN',
            '  [inline_keyboard] b -> add_traffic_confirm:DRAFT | b -> discount_enter:DRAFT | b -> draft_cancel:DRAFT',
            '  [inline_keyboard] b -> order_summary:ORDER | b -> select_store_payment_method:ORDER:CARD_METHOD | b -> select_store_payment_method:ORDER:ZIBAL_METHOD',
            '  [inline_keyboard] b -> payment_check:PAYMENT | b -> renewal_confirm:SERVICE | b -> buy_service',
        ])], $lines);
    }

    public function testIdsInTextAreNamedByTheTextBeforeThemAndOtherNumbersStay(): void
    {
        $lines = BotTranscript::lines([self::message("تمدید سرویس #1\nPayment ID: 1\nOrder ID: 1\nvless://u@edge.test:443 — 1 GB, 10 روز", [])], [], self::IDS);

        self::assertSame(["sendMessage chat=42\n  text: تمدید سرویس #SERVICE\nPayment ID: PAYMENT\nOrder ID: ORDER\nvless://u@edge.test:443 — 1 GB, 10 روز"], $lines);
    }

    public function testOpaqueValuesAreReplacedByValue(): void
    {
        $lines = BotTranscript::lines([self::message('کد پیگیری: AB12CD, نام: tg_abc_1', [])], ['AB12CD' => 'TRACKING', 'tg_abc_1' => 'FINAL_USERNAME'], self::IDS);

        self::assertSame(["sendMessage chat=42\n  text: کد پیگیری: TRACKING, نام: FINAL_USERNAME"], $lines);
    }

    public function testAnUnregisteredIdFailsInsteadOfLeakingTheAutoincrement(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SERVICE id 7 is not registered');

        BotTranscript::lines([self::message('x', [['service_view:7']])], [], self::IDS);
    }

    public function testANumericArgumentOfAnUnknownRouteFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CALLBACK_ARGUMENTS');

        BotTranscript::lines([self::message('x', [['brand_new_route:1']])], [], self::IDS);
    }

    public function testABareNumberCannotBeReplacedByValue(): void
    {
        $this->expectException(\LogicException::class);

        BotTranscript::lines([self::message('x', [])], ['1' => 'PLAN'], self::IDS);
    }

    /**
     * @param list<list<string>> $callbackRows
     *
     * @return array{url: string, options: array<string, mixed>}
     */
    private static function message(string $text, array $callbackRows): array
    {
        $keyboard = array_map(static fn (array $row): array => array_map(static fn (string $data): array => ['text' => 'b', 'callback_data' => $data], $row), $callbackRows);
        $body = ['chat_id' => 42, 'text' => $text];
        if ([] !== $keyboard) {
            $body['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        return ['url' => 'https://api.telegram.org/bot123:abc/sendMessage', 'options' => ['json' => $body]];
    }
}
