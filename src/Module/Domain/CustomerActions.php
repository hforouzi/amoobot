<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * What a customer may do with one service right now, in one channel (docs/design/product-modules.md 2.2
 * customerActions()). PM1d: the names a module offers and the link of its own page, if it has one; the channel's
 * keyboard or template still draws the buttons, so their texts and layout do not move. Stands in for 2.2's
 * list<CustomerAction> until a module needs a paid action that names its order type (PM2b/PM3a).
 */
final readonly class CustomerActions
{
    /** The bot's service view (TelegramKeyboardFactory::userServiceDetail()). */
    public const CHANNEL_BOT = 'bot';
    /** The Mini App's service view (miniapp/view/service.html.twig). */
    public const CHANNEL_MINIAPP = 'miniapp';

    /** Deliver the subscription link into the customer's chat. */
    public const SEND_LINK = 'send_link';
    /** Deliver the subscription link's QR code (or the configs' QR codes) into the chat. */
    public const SEND_QR = 'send_qr';
    /** Deliver the connection configs into the chat. */
    public const RESEND_CONFIG = 'resend_config';
    /** Read the usage from the backend again. */
    public const REFRESH = 'refresh';
    /** Buy a renewal (a paid order, priced by the core). */
    public const RENEW = 'renew';
    /** Buy extra traffic (a paid order, priced by the core). */
    public const ADD_TRAFFIC = 'add_traffic';
    /** Give the service new connection links (an owner who got it through a claim link). */
    public const RESET_LINKS = 'reset_links';

    /**
     * @param list<string> $offered the action names above that the customer may take now
     * @param string|null  $pageUrl the service's own customer page, if the channel shows a button for it
     */
    public function __construct(
        public array $offered,
        public ?string $pageUrl = null,
    ) {
    }

    public function has(string $action): bool
    {
        return \in_array($action, $this->offered, true);
    }
}
