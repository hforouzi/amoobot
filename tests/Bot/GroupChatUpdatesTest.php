<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\TelegramUpdateHandler;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bot works in private chats only (TelegramChatScope): an update of a group, a supergroup or a channel creates no
 * account, gets no answer (no Bot API call at all) and is not written to bot_message_log, whether it arrives through
 * the webhook, long polling or straight into TelegramUpdateHandler. The bot does not leave the chat. Updates of a
 * private chat, and updates without a chat, are handled (and logged) exactly as before.
 */
final class GroupChatUpdatesTest extends IntegrationTestCase
{
    private const ENV_ADMIN = '999000'; // TELEGRAM_ADMIN_CHAT_ID in .env.test
    private const CUSTOMER = '5101';
    private const WEBHOOK_SECRET = 'group-chat-webhook-secret';
    private const WEBHOOK_HEADER = 'group-chat-webhook-header';

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function outsidePrivateChats(): iterable
    {
        $from = ['id' => (int) self::CUSTOMER, 'is_bot' => false, 'first_name' => 'Member'];
        $admin = ['id' => (int) self::ENV_ADMIN, 'is_bot' => false, 'first_name' => 'Admin'];
        $group = ['id' => -4242, 'type' => 'group', 'title' => 'Team'];
        $supergroup = ['id' => -1004242, 'type' => 'supergroup', 'title' => 'Team', 'is_forum' => true];
        $channel = ['id' => -1004343, 'type' => 'channel', 'title' => 'News'];
        $member = ['from' => $admin, 'date' => time(), 'old_chat_member' => ['status' => 'left', 'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']], 'new_chat_member' => ['status' => 'member', 'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']]];

        yield 'group /start' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $group, 'date' => time(), 'text' => '/start']]];
        yield 'supergroup /start@bot' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $supergroup, 'date' => time(), 'text' => '/start@amoo_test_bot']]];
        yield 'supergroup topic message' => [['message' => ['message_id' => 1, 'message_thread_id' => 7, 'is_topic_message' => true, 'from' => $from, 'chat' => $supergroup, 'date' => time(), 'text' => 'hello']]];
        yield 'group /start with a claim payload' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $group, 'date' => time(), 'text' => '/start claim_'.str_repeat('aB3_-', 8).'xyz']]];
        yield 'admin menu button in a group' => [['message' => ['message_id' => 1, 'from' => $admin, 'chat' => $group, 'date' => time(), 'text' => '🛠 مدیریت']]];
        yield 'admin /start in a supergroup' => [['message' => ['message_id' => 1, 'from' => $admin, 'chat' => $supergroup, 'date' => time(), 'text' => '/start']]];
        yield 'bot added: service message with new_chat_members' => [['message' => ['message_id' => 1, 'from' => $admin, 'chat' => $group, 'date' => time(), 'new_chat_members' => [['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']]]]];
        yield 'group edited_message' => [['edited_message' => ['message_id' => 1, 'from' => $from, 'chat' => $group, 'date' => time(), 'edit_date' => time(), 'text' => '/start']]];
        yield 'channel_post' => [['channel_post' => ['message_id' => 1, 'chat' => $channel, 'date' => time(), 'text' => '/start']]];
        yield 'edited_channel_post' => [['edited_channel_post' => ['message_id' => 1, 'chat' => $channel, 'date' => time(), 'edit_date' => time(), 'text' => '/start']]];
        yield 'callback from a group message' => [['callback_query' => ['id' => 'cb1', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $group], 'chat_instance' => 'x', 'data' => 'main_menu']]];
        yield 'admin callback from a supergroup message' => [['callback_query' => ['id' => 'cb2', 'from' => $admin, 'message' => ['message_id' => 1, 'chat' => $supergroup], 'chat_instance' => 'x', 'data' => 'admin_menu']]];
        yield 'callback from a channel post' => [['callback_query' => ['id' => 'cb3', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $channel], 'chat_instance' => 'x', 'data' => 'support']]];
        yield 'my_chat_member group' => [['my_chat_member' => ['chat' => $group] + $member]];
        yield 'my_chat_member supergroup' => [['my_chat_member' => ['chat' => $supergroup] + $member]];
        yield 'my_chat_member channel' => [['my_chat_member' => ['chat' => $channel] + $member]];
        yield 'chat_member supergroup' => [['chat_member' => ['chat' => $supergroup] + $member]];
        yield 'chat_join_request' => [['chat_join_request' => ['chat' => $supergroup, 'from' => $from, 'user_chat_id' => (int) self::CUSTOMER, 'date' => time()]]];
    }

    /**
     * Updates that are kept: their handling is unchanged (what they did before this change, they still do).
     *
     * @return iterable<string, array{array<string, mixed>, bool}> the update, whether it creates the sender's account
     */
    public static function keptWithoutAnAnswer(): iterable
    {
        $from = ['id' => (int) self::CUSTOMER, 'is_bot' => false, 'first_name' => 'Customer'];
        $private = ['id' => (int) self::CUSTOMER, 'type' => 'private', 'first_name' => 'Customer'];
        $member = ['chat' => $private, 'from' => $from, 'date' => time(), 'old_chat_member' => ['status' => 'member', 'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']], 'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot']]];

        yield 'private edited_message' => [['edited_message' => ['message_id' => 1, 'from' => $from, 'chat' => $private, 'date' => time(), 'edit_date' => time(), 'text' => '/start']], false];
        yield 'private my_chat_member (the customer blocked the bot)' => [['my_chat_member' => $member], false];
        yield 'inline_query' => [['inline_query' => ['id' => 'q1', 'from' => $from, 'query' => 'x', 'offset' => '', 'chat_type' => 'supergroup']], false];
        yield 'chosen_inline_result' => [['chosen_inline_result' => ['result_id' => 'r1', 'from' => $from, 'query' => 'x']], false];
        yield 'pre_checkout_query' => [['pre_checkout_query' => ['id' => 'p1', 'from' => $from, 'currency' => 'XTR', 'total_amount' => 1, 'invoice_payload' => 'p']], false];
        yield 'shipping_query' => [['shipping_query' => ['id' => 's1', 'from' => $from, 'invoice_payload' => 'p', 'shipping_address' => []]], false];
        yield 'poll' => [['poll' => ['id' => 'p1', 'question' => 'q', 'options' => [], 'total_voter_count' => 0, 'is_closed' => false, 'is_anonymous' => true, 'type' => 'regular', 'allows_multiple_answers' => false]], false];
        yield 'poll_answer' => [['poll_answer' => ['poll_id' => 'p1', 'user' => $from, 'option_ids' => [0]]], false];
        yield 'private message_reaction' => [['message_reaction' => ['chat' => $private, 'message_id' => 1, 'user' => $from, 'date' => time(), 'old_reaction' => [], 'new_reaction' => []]], false];
        // A message is handled as one: the sender gets an account (as before), whatever the message carries.
        yield 'private web_app_data' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $private, 'date' => time(), 'web_app_data' => ['data' => '{}', 'button_text' => 'Open']]], true];
    }

    #[DataProvider('outsidePrivateChats')]
    public function testTheHandlerDropsIt(array $update): void
    {
        $this->handler()->handle(['update_id' => 900001] + $update);

        $this->assertNothingHappened();
    }

    #[DataProvider('outsidePrivateChats')]
    public function testTheWebhookDropsItWithoutLoggingIt(array $update): void
    {
        self::assertSame(200, $this->webhook(['update_id' => 900002] + $update));

        $this->assertNothingHappened();
    }

    #[DataProvider('keptWithoutAnAnswer')]
    public function testOtherUpdatesAreLoggedAndHandledAsBefore(array $update, bool $createsAccount): void
    {
        self::assertSame(200, $this->webhook(['update_id' => 900003] + $update));

        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM bot_message_log WHERE direction = \'incoming\''), 'logged as before');
        self::assertSame($createsAccount ? 1 : 0, $this->sqlCount('SELECT COUNT(*) FROM telegram_account'));
        self::assertSame([], $this->http->telegramTexts(), 'nothing is answered');
    }

    public function testAPrivateStartStillCreatesTheCustomerAndIsAnswered(): void
    {
        self::assertSame(200, $this->webhook($this->privateStart(900004)));

        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM telegram_account WHERE telegram_id = '.self::CUSTOMER));
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM `user`'));
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM bot_message_log WHERE direction = \'incoming\''));
        self::assertNotSame([], $this->http->telegramTexts());
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true);
            self::assertSame(self::CUSTOMER, (string) (is_array($body) ? $body['chat_id'] ?? '' : ''), 'answered in the private chat');
        }
    }

    public function testTheAdminMenuStillOpensInAPrivateChat(): void
    {
        $this->handler()->handle(['update_id' => 900005, 'message' => [
            'message_id' => 1,
            'from' => ['id' => (int) self::ENV_ADMIN, 'is_bot' => false, 'first_name' => 'Admin'],
            'chat' => ['id' => (int) self::ENV_ADMIN, 'type' => 'private'],
            'date' => time(),
            'text' => '🛠 مدیریت',
        ]]);

        self::assertContains('منوی مدیریت:', $this->http->telegramTexts());
    }

    public function testLongPollingSkipsGroupUpdatesAndHandlesPrivateOnes(): void
    {
        $outside = iterator_to_array(self::outsidePrivateChats());
        $updates = [['update_id' => 910001] + $outside['group /start'][0], ['update_id' => 910002] + $outside['callback from a group message'][0], $this->privateStart(910003)];
        $this->http->onJson('/getUpdates', ['ok' => true, 'result' => $updates]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:poll'));
        $tester->execute(['--once' => true, '--no-delete-webhook' => true, '--timeout' => '0', '--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM bot_message_log WHERE direction = \'incoming\''), 'only the private update is logged');
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM telegram_account WHERE telegram_id = '.self::CUSTOMER));
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM `user`'));
        self::assertSame([], $this->http->requests('/answerCallbackQuery'), 'the group button is not answered');
        self::assertStringNotContainsString('update_id=910001', $tester->getDisplay());
        self::assertStringNotContainsString('update_id=910002', $tester->getDisplay());
        self::assertStringContainsString('Processing update_id=910003', $tester->getDisplay());
    }

    private function assertNothingHappened(): void
    {
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM `user`'), 'no account');
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM telegram_account'), 'no account');
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM bot_message_log'), 'nothing logged');
        self::assertSame([], $this->http->requests('api.telegram.org'), 'no Bot API call: nothing answered, the bot does not leave');
    }

    /**
     * @return array<string, mixed>
     */
    private function privateStart(int $updateId): array
    {
        return ['update_id' => $updateId, 'message' => [
            'message_id' => 1,
            'from' => ['id' => (int) self::CUSTOMER, 'is_bot' => false, 'first_name' => 'Customer'],
            'chat' => ['id' => (int) self::CUSTOMER, 'type' => 'private'],
            'date' => time(),
            'text' => '/start',
        ]];
    }

    private function handler(): TelegramUpdateHandler
    {
        return self::getContainer()->get(TelegramUpdateHandler::class);
    }

    /**
     * Posts $update to the webhook with its own secrets (read when the controller is built), restored afterwards.
     *
     * @param array<string, mixed> $update
     */
    private function webhook(array $update): int
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $saved = [$_SERVER['TELEGRAM_WEBHOOK_SECRET'] ?? null, $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] ?? null];
        $_SERVER['TELEGRAM_WEBHOOK_SECRET'] = $_ENV['TELEGRAM_WEBHOOK_SECRET'] = self::WEBHOOK_SECRET;
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = self::WEBHOOK_HEADER;
        try {
            $client->request('POST', '/telegram/webhook/'.self::WEBHOOK_SECRET, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::WEBHOOK_HEADER], json_encode($update, JSON_THROW_ON_ERROR));
        } finally {
            foreach (['TELEGRAM_WEBHOOK_SECRET' => $saved[0], 'TELEGRAM_WEBHOOK_SECRET_TOKEN' => $saved[1]] as $name => $value) {
                if (null === $value) {
                    unset($_SERVER[$name], $_ENV[$name]);
                } else {
                    $_SERVER[$name] = $_ENV[$name] = $value;
                }
            }
        }

        return $client->getResponse()->getStatusCode();
    }

    private function sqlCount(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }
}
