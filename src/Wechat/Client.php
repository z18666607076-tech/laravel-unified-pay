<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Wechat;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Support\Headers;
use ZiwenZhao\UnifiedPay\Support\Json;

/**
 * Signed WeChat Pay API v3 HTTP calls. Responses are verified with the platform public key.
 */
final class Client
{
    public function __construct(
        private Factory $http,
        private Signer $signer,
        private Credentials $credentials,
    ) {}

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    public function call(string $method, string $path, ?array $json = null, array $query = [], array $extraHeaders = []): array
    {
        $method = strtoupper($method);
        $body = $json === null ? '' : Json::encode($json);
        $signedPath = $this->signedPath($path, $query);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = $this->signer->sign(
            $this->signer->requestMessage($method, $signedPath, $timestamp, $nonce, $body),
            $this->credentials->privateKey(),
        );
        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $this->credentials->mchId(),
            $nonce,
            $signature,
            $timestamp,
            $this->credentials->merchantSerial(),
        );

        $headers = array_merge([
            'Authorization' => $authorization,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent(),
        ], $extraHeaders);

        $pending = $this->pending($headers);

        if ($body !== '') {
            $pending = $pending->withBody($body, 'application/json');
        }

        $response = $pending->send($method, $this->credentials->baseUrl().$signedPath);

        return $this->decode($response);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(array $headers): PendingRequest
    {
        $timeout = config('unified-pay.http.timeout');

        return $this->http
            ->withHeaders($headers)
            ->timeout(is_int($timeout) && $timeout > 0 ? $timeout : 10);
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function signedPath(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        $filtered = [];

        foreach ($query as $key => $value) {
            if ($value !== null) {
                $filtered[$key] = $value;
            }
        }

        return $path.'?'.http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->body();
        $this->verifyResponse($response, $body);

        if ($response->status() === 204 || $body === '') {
            if (! $response->successful()) {
                throw new PaymentException('WeChat Pay rejected the request.', 'wechat', null, 502);
            }

            return [];
        }

        $decoded = Json::decodeObject($body, 'WeChat Pay returned a non-JSON response.');

        if (! $response->successful()) {
            $code = $decoded['code'] ?? null;
            $message = $decoded['message'] ?? null;

            throw new PaymentException(
                'WeChat Pay rejected the request: '.(is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status()),
                'wechat',
                is_string($code) ? $code : null,
                502,
            );
        }

        return $decoded;
    }

    private function verifyResponse(Response $response, string $body): void
    {
        $headers = $this->responseHeaders($response);
        $timestamp = Headers::get($headers, 'Wechatpay-Timestamp');
        $nonce = Headers::get($headers, 'Wechatpay-Nonce');
        $signature = Headers::get($headers, 'Wechatpay-Signature');
        $serial = Headers::get($headers, 'Wechatpay-Serial');

        if ($timestamp === '' || $nonce === '' || $signature === '' || $serial === '') {
            throw new SignatureException('WeChat Pay response is missing signature headers.', 'wechat');
        }

        if (! hash_equals($this->credentials->platformSerial(), $serial)) {
            throw new SignatureException('WeChat Pay response platform serial does not match.', 'wechat');
        }

        $message = $this->signer->notificationMessage($timestamp, $nonce, $body);

        if (! $this->signer->verify($message, $signature, $this->credentials->platformPublicKey())) {
            throw new SignatureException('WeChat Pay response signature is invalid.', 'wechat');
        }
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            $first = $values[0] ?? '';
            $headers[$name] = is_string($first) ? $first : '';
        }

        return $headers;
    }

    private function userAgent(): string
    {
        $agent = config('unified-pay.http.user_agent');

        return is_string($agent) && $agent !== '' ? $agent : 'ziwen-zhao-laravel-unified-pay/0.1';
    }
}
