<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

/**
 * The topics of the customer guide, in the order they are shown (docs/design/help-guide.md, section 1). The value is
 * the stable slug: ?topic=<slug>, and the texts guide.<slug>.title / guide.<slug>.body (bot domain, so the bot-content
 * editor of a later release overrides them under the same keys).
 */
enum GuideTopic: string
{
    case START = 'start';
    case BUY = 'buy';
    case PAY = 'pay';
    case WALLET = 'wallet';
    case CONNECT = 'connect';
    case USAGE = 'usage';
    case RENEW = 'renew';
    case TRAFFIC = 'traffic';
    case TROUBLESHOOT = 'troubleshoot';
    case CLAIM = 'claim';
    case FAQ = 'faq';
    case SUPPORT = 'support';

    /**
     * Whether the topic is listed while the shop is in $state. Fixed here, never by the text: a topic about a feature
     * that is off does not exist for the customer.
     */
    public function isVisible(GuideState $state): bool
    {
        return match ($this) {
            self::BUY => $state->flag('new_orders'),
            self::PAY => $state->flag('payment_methods') || $state->flag('wallet_payment'),
            self::WALLET => $state->flag('wallet'),
            self::RENEW => $state->flag('renewals'),
            self::TRAFFIC => $state->flag('add_traffic'),
            self::CLAIM => $state->flag('claims'),
            self::START, self::CONNECT, self::USAGE, self::TROUBLESHOOT, self::FAQ, self::SUPPORT => true,
        };
    }

    public function titleKey(): string
    {
        return 'guide.'.$this->value.'.title';
    }

    public function bodyKey(): string
    {
        return 'guide.'.$this->value.'.body';
    }
}
