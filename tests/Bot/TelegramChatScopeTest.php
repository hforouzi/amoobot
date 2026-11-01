<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\TelegramChatScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which updates belong to a chat that is not private (dropped) and which are kept: every update type Telegram sends,
 * with a private chat, a group, a supergroup, a channel, and without a chat.
 */
final class TelegramChatScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{array<mixed>, bool}>
     */
    public static function updates(): iterable
    {
        $private = ['id' => 42, 'type' => 'private'];
        $group = ['id' => -4242, 'type' => 'group'];
        $supergroup = ['id' => -1004242, 'type' => 'supergroup'];
        $channel = ['id' => -1004343, 'type' => 'channel'];
        $from = ['id' => 42, 'is_bot' => false, 'first_name' => 'A'];

        foreach (['message', 'edited_message', 'business_message', 'edited_business_message'] as $type) {
            yield $type.' private' => [[$type => ['message_id' => 1, 'from' => $from, 'chat' => $private, 'text' => '/start']], false];
            yield $type.' group' => [[$type => ['message_id' => 1, 'from' => $from, 'chat' => $group, 'text' => '/start']], true];
            yield $type.' supergroup' => [[$type => ['message_id' => 1, 'from' => $from, 'chat' => $supergroup, 'text' => '/start']], true];
        }
        yield 'message web_app_data private' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $private, 'web_app_data' => ['data' => 'x', 'button_text' => 'b']]], false];
        foreach (['channel_post', 'edited_channel_post'] as $type) {
            yield $type => [[$type => ['message_id' => 1, 'chat' => $channel, 'text' => 'news']], true];
        }

        yield 'callback_query private' => [['callback_query' => ['id' => 'c', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $private], 'data' => 'main_menu']], false];
        yield 'callback_query group' => [['callback_query' => ['id' => 'c', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $group], 'data' => 'main_menu']], true];
        yield 'callback_query supergroup' => [['callback_query' => ['id' => 'c', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $supergroup], 'data' => 'main_menu']], true];
        yield 'callback_query channel' => [['callback_query' => ['id' => 'c', 'from' => $from, 'message' => ['message_id' => 1, 'chat' => $channel], 'data' => 'main_menu']], true];
        yield 'callback_query of an inline message (no chat)' => [['callback_query' => ['id' => 'c', 'from' => $from, 'inline_message_id' => 'i', 'data' => 'main_menu']], false];
        yield 'callback_query, message without chat' => [['callback_query' => ['id' => 'c', 'from' => $from, 'message' => ['message_id' => 1], 'data' => 'main_menu']], true];

        $member = ['chat' => $group, 'from' => $from, 'date' => 1, 'old_chat_member' => [], 'new_chat_member' => []];
        foreach (['my_chat_member', 'chat_member'] as $type) {
            yield $type.' private' => [[$type => ['chat' => $private] + $member], false];
            yield $type.' group' => [[$type => $member], true];
            yield $type.' supergroup' => [[$type => ['chat' => $supergroup] + $member], true];
            yield $type.' channel' => [[$type => ['chat' => $channel] + $member], true];
        }
        yield 'chat_join_request' => [['chat_join_request' => ['chat' => $supergroup, 'from' => $from, 'user_chat_id' => 42, 'date' => 1]], true];
        yield 'message_reaction group' => [['message_reaction' => ['chat' => $group, 'message_id' => 1, 'date' => 1]], true];
        yield 'message_reaction private' => [['message_reaction' => ['chat' => $private, 'message_id' => 1, 'date' => 1]], false];
        yield 'message_reaction_count' => [['message_reaction_count' => ['chat' => $channel, 'message_id' => 1, 'date' => 1]], true];
        yield 'chat_boost' => [['chat_boost' => ['chat' => $channel, 'boost' => []]], true];
        yield 'removed_chat_boost' => [['removed_chat_boost' => ['chat' => $channel, 'boost_id' => 'b']], true];
        yield 'deleted_business_messages private' => [['deleted_business_messages' => ['business_connection_id' => 'b', 'chat' => $private, 'message_ids' => [1]]], false];

        // No chat: kept exactly as today.
        yield 'inline_query' => [['inline_query' => ['id' => 'q', 'from' => $from, 'query' => '', 'offset' => '', 'chat_type' => 'supergroup']], false];
        yield 'chosen_inline_result' => [['chosen_inline_result' => ['result_id' => 'r', 'from' => $from, 'query' => '']], false];
        yield 'shipping_query' => [['shipping_query' => ['id' => 's', 'from' => $from, 'invoice_payload' => 'p']], false];
        yield 'pre_checkout_query' => [['pre_checkout_query' => ['id' => 'p', 'from' => $from, 'currency' => 'XTR', 'total_amount' => 1, 'invoice_payload' => 'p']], false];
        yield 'poll' => [['poll' => ['id' => 'p', 'question' => 'q', 'options' => []]], false];
        yield 'poll_answer (voter_chat is not the update\'s chat)' => [['poll_answer' => ['poll_id' => 'p', 'voter_chat' => $channel, 'option_ids' => [0]]], false];
        yield 'business_connection' => [['business_connection' => ['id' => 'b', 'user' => $from, 'user_chat_id' => 42, 'date' => 1]], false];
        yield 'purchased_paid_media' => [['purchased_paid_media' => ['from' => $from, 'paid_media_payload' => 'p']], false];
        yield 'nothing but update_id' => [[], false];

        // Fail closed: a chat whose type is missing, unknown or malformed.
        yield 'chat without type' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => ['id' => 42], 'text' => '/start']], true];
        yield 'chat of an unknown type' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => ['id' => 42, 'type' => 'Private'], 'text' => '/start']], true];
        yield 'chat that is not an object' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => 42, 'text' => '/start']], true];
        yield 'chat null' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => null, 'text' => '/start']], true];
        yield 'reply to a group message inside a private chat' => [['message' => ['message_id' => 1, 'from' => $from, 'chat' => $private, 'reply_to_message' => ['message_id' => 2, 'chat' => $group]]], false];
    }

    /**
     * @param array<mixed> $update
     */
    #[DataProvider('updates')]
    public function testOnlyChatsThatAreNotPrivateAreDropped(array $update, bool $dropped): void
    {
        self::assertSame($dropped, TelegramChatScope::isOutsidePrivateChats(['update_id' => 1] + $update));
    }
}
