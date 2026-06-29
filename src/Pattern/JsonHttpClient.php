<?php

declare(strict_types=1);

namespace LaravelAudit\Pattern;

final class JsonHttpClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function post(string $url, array $payload, ?string $apiKey = null, int $timeout = 30): ?array
    {
        $results = $this->postMany($url, [$payload], $apiKey, $timeout);

        return $results[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>|null>
     */
    public function postMany(string $url, array $payloads, ?string $apiKey = null, int $timeout = 30): array
    {
        if ($payloads === []) {
            return [];
        }

        if (! function_exists('curl_multi_init')) {
            return array_map(
                fn (array $payload): ?array => $this->postViaStream($url, $payload, $apiKey, $timeout),
                $payloads,
            );
        }

        return $this->postManyViaCurl($url, $payloads, $apiKey, $timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function postViaStream(string $url, array $payload, ?string $apiKey, int $timeout): ?array
    {
        if ($payload === []) {
            throw new \InvalidArgumentException('LLM request payload cannot be empty.');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $this->headers($apiKey)),
                'content' => $encoded,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false || ! $this->responseSuccessful($http_response_header)) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>|null>
     */
    private function postManyViaCurl(string $url, array $payloads, ?string $apiKey, int $timeout): array
    {
        $multi = curl_multi_init();
        /** @var array<int, \CurlHandle> $handles */
        $handles = [];

        foreach ($payloads as $index => $payload) {
            if ($payload === []) {
                throw new \InvalidArgumentException('LLM request payload cannot be empty.');
            }

            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $handle = curl_init($url);

            if ($handle === false) {
                continue;
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $this->headers($apiKey),
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
        }

        $running = null;

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];

        foreach ($handles as $index => $handle) {
            $body = curl_multi_getcontent($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

            if (! is_string($body) || $statusCode < 200 || $statusCode >= 300) {
                $results[$index] = null;
            } else {
                $decoded = json_decode($body, true);
                $results[$index] = is_array($decoded) ? $decoded : null;
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        ksort($results);

        return array_values($results);
    }

    /**
     * @return list<string>
     */
    private function headers(?string $apiKey): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer '.$apiKey;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     */
    private function responseSuccessful(array $headers): bool
    {
        if ($headers === []) {
            return false;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) !== 1) {
            return false;
        }

        $status = (int) $matches[1];

        return $status >= 200 && $status < 300;
    }
}
