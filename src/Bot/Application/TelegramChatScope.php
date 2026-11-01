<?php

declare(strict_types=1);

namespace App\Bot\Application;

/**
 * The bot works in private chats only (docs/design/activity-group-and-daily-report.md, section 4.4). An update that
 * belongs to a group, a supergroup or a channel is dropped before anything else runs: it is not written to
 * bot_message_log (TelegramWebhookController, TelegramPollCommand), no account is created and nothing is answered
 * (TelegramUpdateHandler). The bot does not leave the chat.
 *
 * The chat of an update: "chat" of the update object (message, edited_message, channel_post, edited_channel_post,
 * business messages, my_chat_member, chat_member, chat_join_request, message_reaction(_count), chat_boost,
 * removed_chat_boost, deleted_business_messages), or for a callback_query the chat of the message its button is on
 * ("message.chat"). Updates without a chat (inline_query, chosen_inline_result, shipping_query, pre_checkout_query,
 * poll, poll_answer, business_connection, purchased_paid_media, a callback_query of an inline message) are kept as
 * they are. A chat without the type "private" (any other type, a missing or malformed one) is dropped: fail closed.
 */
final class TelegramChatScope
{
    /** Whether $update belongs to a chat that is not a private chat with the bot (see the class comment). */
    public static function isOutsidePrivateChats(array $update): bool
    {
        foreach ($update as $key => $object) {
            if ('update_id' === $key || !is_array($object)) {
                continue;
            }

            if (array_key_exists('chat', $object) && !self::isPrivate($object['chat'])) {
                return true;
            }
            if ('callback_query' === $key && array_key_exists('message', $object)
                && (!is_array($object['message']) || !self::isPrivate($object['message']['chat'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivate(mixed $chat): bool
    {
        return is_array($chat) && 'private' === ($chat['type'] ?? null);
    }
}
