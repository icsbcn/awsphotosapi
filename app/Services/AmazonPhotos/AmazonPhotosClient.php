<?php

namespace App\Services\AmazonPhotos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmazonPhotosClient
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
    ];

    private readonly string $baseUrl;

    private readonly string $tld;

    public function __construct()
    {
        $this->tld = config('amazon-photos.tld', 'com');
        $this->baseUrl = "https://www.amazon.{$this->tld}/drive/v1";
    }

    public function get(string $path, array $params = []): array
    {
        return $this->request('get', $path, ['query' => $params]);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl.$path;

        Log::debug("Amazon Photos API {$method} {$url}", $options);

        $response = $this->makeClient()
            ->retry(
                times: config('amazon-photos.retry_times', 3),
                sleepMilliseconds: fn (int $attempt) => (int) (config('amazon-photos.retry_sleep_ms', 1000) * (2 ** ($attempt - 1))),
                when: fn (\Throwable $e, PendingRequest $request) => $e instanceof RequestException && in_array($e->response->status(), [400, 401, 409, 429, 500, 503]),
            )
            ->$method($url, $options['query'] ?? []);

        $response->throw();

        return $response->json();
    }

    private function makeClient(): PendingRequest
    {
        $sessionId = config('amazon-photos.session_id');
        $ubid = config('amazon-photos.ubid');
        $at = config('amazon-photos.at');
        $tld = $this->tld;

        // Normalise TLD to a cookie key suffix (co.uk → acuk, com → main, etc.)
        $cookieSuffix = match ($tld) {
            'com' => 'main',
            'co.uk' => 'acuk',
            'ca' => 'acbca',
            'de' => 'acde',
            'fr' => 'acfr',
            'it' => 'acit',
            'es' => 'acbes',
            'co.jp' => 'acjp',
            'com.au' => 'acau',
            default => str_replace(['.', '-'], '', $tld),
        };

        $cookieString = implode('; ', [
            "session-id={$sessionId}",
            "ubid-{$cookieSuffix}={$ubid}",
            "at-{$cookieSuffix}={$at}",
        ]);

        return Http::baseUrl($this->baseUrl)
            ->timeout(config('amazon-photos.timeout', 60))
            ->connectTimeout(config('amazon-photos.connect_timeout', 10))
            ->withHeaders([
                'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
                'Cookie' => $cookieString,
                'x-amzn-sessionid' => $sessionId,
                'Accept' => 'application/json',
                'x-amz-clouddrive-appid' => 'YW16bjEuYXBwbGljYXRpb24uMjllMmU2YjgxZDE3NDhjYWIxZjM4MDQwZGZmMjJkYmY',
                'x-requested-with' => 'XMLHttpRequest',
            ]);
    }
}
