<?php
/**
 * Ebioro Merchant API client for PrestaShop.
 *
 * Framework-light port of the proven WooCommerce handler. The signing scheme is
 * the critical part and is byte-for-byte identical to the other Ebioro clients:
 *
 *   tosign    = path + timestamp + method + body
 *   signature = HMAC-SHA256(tosign, api_secret)            (hex)
 *   headers   = X-Digest-Key, X-Digest-Signature, X-Digest-Timestamp
 *
 * The body that is signed MUST be the exact byte string that is sent, so the
 * same json_encode flags are used for both.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EbioroApiHandler
{
    const PROD_URL = 'https://merchant-api.ebioro.com';
    const TEST_URL = 'https://test-merchant.ebioro.com';

    /** Same flags used for signing and for the request body — they must match. */
    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var bool */
    private $testMode;

    public function __construct($apiKey, $apiSecret, $testMode = true)
    {
        $this->apiKey = (string) $apiKey;
        $this->apiSecret = (string) $apiSecret;
        $this->testMode = (bool) $testMode;
    }

    private function baseUrl()
    {
        return $this->testMode ? self::TEST_URL : self::PROD_URL;
    }

    private static function log($message)
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[Ebioro] ' . $message, 1, null, 'EbioroPayment');
        }
    }

    /**
     * Build the HMAC auth headers for a request.
     *
     * @param string $path   e.g. '/payments'
     * @param string $method HTTP method
     * @param string $body   the exact request body string ('' for GET)
     * @return array<string,string>
     */
    private function buildAuthHeaders($path, $method, $body)
    {
        // UTC seconds — must match the server's ±5 min window.
        $timestamp = (string) time();
        $tosign = $path . $timestamp . $method . $body;
        $signature = hash_hmac('sha256', $tosign, $this->apiSecret);

        return array(
            'Content-Type: application/json',
            'X-Digest-Key: ' . $this->apiKey,
            'X-Digest-Signature: ' . $signature,
            'X-Digest-Timestamp: ' . $timestamp,
        );
    }

    /**
     * Make an authenticated request.
     *
     * @return array{0:bool,1:mixed} [success, decoded body | error message]
     */
    private function sendRequest($path, array $params = array(), $method = 'GET', array $extraHeaders = array())
    {
        $method = strtoupper($method);
        $body = in_array($method, array('POST', 'PUT'), true)
            ? json_encode($params, self::JSON_FLAGS)
            : '';

        // Auth headers are signed over path+timestamp+method+body; extra headers
        // (e.g. Idempotency-Key) are not part of the signature, so they are safe
        // to append.
        $headers = array_merge($this->buildAuthHeaders($path, $method, $body), $extraHeaders);
        $url = $this->baseUrl() . $path;

        if ('GET' === $method && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        if ('' !== $body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (false === $raw) {
            self::log('Request transport error: ' . $curlErr);
            return array(false, $curlErr ?: 'Request failed');
        }

        $decoded = json_decode($raw, true);
        if (null === $decoded && JSON_ERROR_NONE !== json_last_error()) {
            self::log('Could not decode response: ' . json_last_error_msg());
            return array(false, 'Invalid JSON response');
        }

        if (in_array($status, array(200, 201), true)) {
            return array(true, $decoded);
        }

        $messages = array(
            400 => 'Bad request to the Ebioro API.',
            401 => 'Authentication error — check your API key and secret.',
            429 => 'Ebioro API rate limit exceeded.',
        );
        $msg = isset($messages[$status]) ? $messages[$status] : ('Unexpected API status ' . $status);
        self::log($msg . ' | body: ' . $raw);

        return array(false, $msg);
    }

    /**
     * Create a hosted-checkout payment.
     *
     * @param int    $amountMinor    amount already in the smallest unit (cents)
     * @param string $currency       ISO 4217 code
     * @param array  $metadata       arbitrary data echoed back on the webhook (carries order_id)
     * @param string $idempotencyKey optional — dedupes retries/double-submits so an
     *                               order never spawns more than one Ebioro payment
     * @return array{0:bool,1:mixed}
     */
    public function createPayment($amountMinor, $currency, array $metadata, $redirectUrl, $cancelUrl, $webhookUrl, $name, $description, $locale = 'en', $idempotencyKey = null)
    {
        $args = array(
            'name' => $name,
            'description' => $description,
            'amount' => array(
                'value' => (int) $amountMinor,
                'currency' => $currency,
            ),
            'redirectUrl' => $redirectUrl,
            'cancelUrl' => $cancelUrl,
            'webhookUrl' => $webhookUrl,
            'metadata' => $metadata,
            'locale' => $locale,
        );

        $extraHeaders = array();
        if (!empty($idempotencyKey)) {
            $extraHeaders[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        return $this->sendRequest('/payments', $args, 'POST', $extraHeaders);
    }

    /**
     * Verify a webhook's X-WEBHOOK-AUTH signature against the raw request body.
     * Constant-time comparison. Pass the RAW body bytes, never a re-encoded copy.
     */
    public static function verifyWebhookSignature($rawBody, $signature, $apiSecret)
    {
        if (empty($signature) || empty($apiSecret)) {
            return false;
        }
        $expected = hash_hmac('sha256', (string) $rawBody, (string) $apiSecret);
        return hash_equals($expected, (string) $signature);
    }

    /** Convert a decimal amount to the smallest currency unit (cents). */
    public static function toMinorUnit($amount)
    {
        return (int) round(((float) $amount) * 100);
    }
}
