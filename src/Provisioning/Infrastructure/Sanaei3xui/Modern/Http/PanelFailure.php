<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * Why a call to a 3x-ui panel failed, one case per thing the operator fixes differently.
 * The values are stable: they are stored as the panel's probe status and used as translation keys.
 */
enum PanelFailure: string
{
    /** Host name does not resolve. */
    case NETWORK_DNS = 'network_dns';
    /** Nothing listens on that host/port (or a firewall rejects it). */
    case NETWORK_REFUSED = 'network_refused';
    /** No answer in time (firewall dropping packets, panel overloaded, wrong port). */
    case NETWORK_TIMEOUT = 'network_timeout';
    /** Any other connection-level failure. */
    case NETWORK_OTHER = 'network_other';
    /** HTTPS certificate not trusted, expired, wrong host name, or not the pinned one. */
    case TLS_CERTIFICATE = 'tls_certificate';
    /** HTTP 401/403: missing, wrong or revoked API token. */
    case AUTH_REJECTED = 'auth_rejected';
    /** HTTP 404 or an HTML page instead of JSON: wrong URL or web base path, or the API does not exist there. */
    case WRONG_URL = 'wrong_url';
    /** The panel answered {success: false, msg}. */
    case PANEL_REJECTED = 'panel_rejected';
    /** JSON, but not the {success, msg, obj} envelope or not the shape the call expects. */
    case UNEXPECTED_RESPONSE = 'unexpected_response';
    /** Any other HTTP error status (5xx, 429, redirect). */
    case HTTP_ERROR = 'http_error';
    /** URL or token missing, or the URL is not acceptable (see the message). */
    case NOT_CONFIGURED = 'not_configured';

    public function isNetwork(): bool
    {
        return in_array($this, [self::NETWORK_DNS, self::NETWORK_REFUSED, self::NETWORK_TIMEOUT, self::NETWORK_OTHER], true);
    }

    /** The panel could not be reached at all (as opposed to: it answered, but not as expected). */
    public function isUnreachable(): bool
    {
        return $this->isNetwork() || self::TLS_CERTIFICATE === $this;
    }
}
