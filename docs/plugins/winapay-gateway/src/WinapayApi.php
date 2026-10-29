<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Winapay;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP layer for the Winapay REST web service.
 *
 * TLS verification stays on, redirects are not followed, the body is capped and anything that is
 * not a JSON object (HTML error pages, empty bodies) becomes a WinapayException.
 */
final class WinapayApi
{
    public const BASE_URL = 'https://winapay.io';
    public const REQUEST_PATH = '/webservice/rest/PaymentRequest';
    public const VERIFY_PATH = '/webservice/rest/PaymentVerification';
    public const START_PAY_URL = self::BASE_URL.'/startPay/';
    public const HOSTS = ['winapay.io', 'www.winapay.io'];

    public const FORMAT_FORM = 'form';
    public const FORMAT_JSON = 'json';

    /**
     * Symfony HttpClient does not allow a separate connect timeout (CURLOPT_CONNECTTIMEOUT is
     * blocked), so this is an inactivity timeout: it bounds a stalled connect and a silent server.
     */
    public const IDLE_TIMEOUT = 5.0;
    public const MAX_DURATION = 30.0;
    public const MAX_RESPONSE_BYTES = 65536;

    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array<mixed> the decoded JSON object
     *
     * @throws WinapayException
     */
    public function post(string $path, array $params, string $format): array
    {
        $client = $this->httpClient ?? HttpClient::create();
        $options = [
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => 0,
            'verify_peer' => true,
            'verify_host' => true,
            'headers' => ['Accept' => 'application/json'],
        ];
        if (self::FORMAT_JSON === $format) {
            $options['json'] = $params;
        } else {
            // RFC 1738 encoding of every value, as application/x-www-form-urlencoded requires.
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $options['body'] = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        }

        // Aborts the download as soon as the body grows past the cap.
        $options['on_progress'] = static function (int $downloaded): void {
            if ($downloaded > self::MAX_RESPONSE_BYTES) {
                throw new WinapayException('Winapay response is too large.');
            }
        };

        try {
            $response = $client->request('POST', self::BASE_URL.$path, $options);
            $httpStatus = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            if ($exception->getPrevious() instanceof WinapayException) {
                throw $exception->getPrevious();
            }
            if (str_contains($exception->getMessage(), 'timeout')) {
                throw new WinapayException('Winapay did not respond in time.', previous: $exception);
            }
            throw new WinapayException('Could not reach Winapay: '.$exception->getMessage(), previous: $exception);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new WinapayException('Winapay response is too large.');
        }

        $body = trim($body);
        $decoded = '' === $body ? null : json_decode($body, true, 32);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new WinapayException(sprintf(
                '' === $body ? 'Winapay returned an empty response (HTTP %d).'
                    : (str_starts_with($body, '<') ? 'Winapay returned an HTML page instead of JSON (HTTP %d).' : 'Winapay returned an invalid response (HTTP %d).'),
                $httpStatus,
            ));
        }

        return $decoded;
    }
}
