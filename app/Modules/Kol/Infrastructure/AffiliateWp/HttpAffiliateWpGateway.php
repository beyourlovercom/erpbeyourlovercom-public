<?php

namespace App\Modules\Kol\Infrastructure\AffiliateWp;

use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

final class HttpAffiliateWpGateway implements AffiliateWpGateway
{
    private const string Namespace = '/wp-json/andy-core/v1/erp';

    private const string UserAgent = 'Mozilla/5.0 BYL-ERP-AffiliateWP-Connector/1.0';

    private const string SupportedContractVersion = '1';

    /** Resources allowed in snapshot(); fail-closed for any other value. */
    private const array AllowedSnapshotResources = ['affiliates', 'coupons', 'referrals', 'payouts', 'email-templates'];

    public function descriptor(): array
    {
        $configured = $this->configured();

        return [
            'configured' => $configured,
            'url' => $configured ? $this->validatedBaseUrl() : '',
            'connection_key' => $this->connectionKey(),
            'key_id' => $this->keyId(),
        ];
    }

    public function health(): ConnectorResponse
    {
        return $this->get('/health');
    }

    public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse
    {
        if (! in_array($resource, self::AllowedSnapshotResources, true)) {
            throw ConnectorException::nonRetryable(
                'Unsupported snapshot resource: '.$resource,
                'CONNECTOR_ERROR',
            );
        }

        $query = array_filter([
            'limit' => 100,
            'cursor' => $cursor,
            'updated_after' => $updatedAfter,
        ], fn ($v) => $v !== null);

        return $this->get('/snapshot/'.rawurlencode($resource), $query);
    }

    public function checkAffiliateIdentity(string $email): ConnectorResponse
    {
        return $this->post('/affiliates/check', ['email' => $email], null);
    }

    public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse
    {
        return $this->post('/affiliates/provision', $payload, $idempotencyKey);
    }

    public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse
    {
        return $this->post('/affiliates/'.$affiliateId.'/status', ['status' => $status], $idempotencyKey);
    }

    public function updateWordpressPassword(int $wordpressUserId, array $payload, string $idempotencyKey): ConnectorResponse
    {
        if ($wordpressUserId <= 0) {
            throw ConnectorException::nonRetryable('WordPress user ID must be positive.', 'VALIDATION_FAILED');
        }

        return $this->post('/wordpress/users/'.$wordpressUserId.'/password', $payload, $idempotencyKey);
    }

    public function checkCoupon(string $code): ConnectorResponse
    {
        return $this->post('/coupons/check', ['code' => $code], null);
    }

    public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse
    {
        return $this->post('/coupons/provision', $payload, $idempotencyKey);
    }

    public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse
    {
        return $this->post('/payouts/complete', $payload, $idempotencyKey);
    }

    private function get(string $path, array $query = []): ConnectorResponse
    {
        $this->guardConfigured();

        $baseUrl = $this->validatedBaseUrl();
        $pathWithQuery = self::Namespace.$path.(count($query) > 0 ? '?'.http_build_query($query) : '');

        return $this->executeWithRetry(function () use ($baseUrl, $pathWithQuery): ConnectorResponse {
            [$timestamp, $nonce] = $this->freshNonceAndTimestamp();
            $headers = HmacV1Signer::headers(
                'GET', $pathWithQuery, $this->connectionKey(), $this->keyId(),
                $this->sharedSecret(), '', null, $timestamp, $nonce,
            );

            $request = Http::withHeaders($headers)
                ->withHeaders(['User-Agent' => self::UserAgent])
                ->acceptJson()
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->withoutRedirecting()
                ->withOptions(['verify' => true]);

            if (! $this->shouldVerifyTls()) {
                $request->withoutVerifying();
            }

            return $this->parseResponse($request->get($baseUrl.$pathWithQuery));
        });
    }

    private function post(string $path, array $payload, ?string $idempotencyKey): ConnectorResponse
    {
        $this->guardConfigured();
        $this->guardMutationIdempotencyKey($path, $idempotencyKey);

        $baseUrl = $this->validatedBaseUrl();
        $pathWithQuery = self::Namespace.$path;
        $body = $this->encodeJson($payload);

        // Idempotency key is preserved across all retry attempts; only nonce/timestamp/signature are refreshed.
        return $this->executeWithRetry(function () use ($baseUrl, $pathWithQuery, $body, $idempotencyKey): ConnectorResponse {
            [$timestamp, $nonce] = $this->freshNonceAndTimestamp();
            $headers = HmacV1Signer::headers(
                'POST', $pathWithQuery, $this->connectionKey(), $this->keyId(),
                $this->sharedSecret(), $body, $idempotencyKey, $timestamp, $nonce,
            );

            $request = Http::withHeaders($headers)
                ->withHeaders(['User-Agent' => self::UserAgent])
                ->acceptJson()
                ->contentType('application/json')
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->withoutRedirecting()
                ->withOptions(['verify' => true])
                ->withBody($body, 'application/json');

            if (! $this->shouldVerifyTls()) {
                $request->withoutVerifying();
            }

            return $this->parseResponse($request->post($baseUrl.$pathWithQuery));
        });
    }

    /**
     * Executes $attempt up to retry_times+1 total tries.
     * Retries only on retryable ConnectorException or ConnectionException.
     * Fresh nonce/timestamp/signature is produced inside $attempt on every call.
     * On exhaustion, throws ConnectorException::retryable.
     */
    private function executeWithRetry(callable $attempt): ConnectorResponse
    {
        $maxAttempts = max(1, $this->retryTimes() + 1);
        $sleepMs = $this->retrySleepMs();
        $lastException = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            try {
                return $attempt();
            } catch (ConnectorException $e) {
                if (! $e->retryable) {
                    throw $e;
                }
                $lastException = $e;
            } catch (ConnectionException $e) {
                $lastException = ConnectorException::retryable(
                    'Andy Core connector transport error: '.$e->getMessage(),
                    'PROVIDER_UNAVAILABLE',
                );
            }
        }

        throw $lastException ?? ConnectorException::retryable(
            'Andy Core connector unavailable after retries.',
            'PROVIDER_UNAVAILABLE',
        );
    }

    private function parseResponse(Response $response): ConnectorResponse
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            throw ConnectorException::nonRetryable(
                'Andy Core connector received unsafe redirect.',
                'CONNECTOR_ERROR',
                $status,
            );
        }

        if ($response->serverError()) {
            throw ConnectorException::retryable(
                'Andy Core connector server error.',
                'PROVIDER_UNAVAILABLE',
                $status,
            );
        }

        $raw = $response->json();

        if (! is_array($raw)) {
            throw ConnectorException::retryable('Connector response is not valid JSON.', 'PROVIDER_UNAVAILABLE', $status);
        }

        $errorCode = (string) ($raw['code'] ?? '');

        if ($response->clientError()) {
            throw $this->classifyClientError($errorCode, $status, (string) ($raw['message'] ?? 'Connector client error.'));
        }

        // Fail-closed envelope validation: all fields must satisfy exact contract.
        if (($raw['ok'] ?? null) !== true) {
            throw ConnectorException::nonRetryable(
                'Connector envelope ok is not true.',
                'CONNECTOR_ERROR',
                $status,
            );
        }

        $contractVersion = (string) ($raw['contract_version'] ?? '');
        if ($contractVersion !== self::SupportedContractVersion) {
            throw ConnectorException::nonRetryable(
                'Unsupported connector contract version: '.$contractVersion,
                'CONTRACT_VERSION_UNSUPPORTED',
                $status,
            );
        }

        $requestId = (string) ($raw['request_id'] ?? '');
        if ($requestId === '') {
            throw ConnectorException::nonRetryable(
                'Connector response missing request_id.',
                'CONNECTOR_ERROR',
                $status,
            );
        }

        $responseConnectionKey = (string) ($raw['connection_key'] ?? '');
        if ($responseConnectionKey !== $this->connectionKey()) {
            throw ConnectorException::nonRetryable(
                'Connector connection key mismatch in response.',
                'AUTH_INVALID',
                $status,
            );
        }

        if (! array_key_exists('data', $raw)) {
            throw ConnectorException::nonRetryable(
                'Connector response missing data key.',
                'CONNECTOR_ERROR',
                $status,
            );
        }

        return ConnectorResponse::fromArray($raw);
    }

    private function classifyClientError(string $errorCode, int $httpStatus, string $message): ConnectorException
    {
        return match ($errorCode) {
            'AUTH_INVALID',
            'REPLAY_DETECTED',
            'CONTRACT_VERSION_UNSUPPORTED',
            'AFFILIATE_ID_CONFLICT',
            'WP_USER_CONFLICT',
            'AFFILIATE_CONFLICT',
            'BINDING_MISMATCH',
            'PROVIDER_IDENTITY_MISMATCH',
            'WP_USER_NOT_FOUND',
            'AFFILIATE_NOT_FOUND',
            'COUPON_CONFLICT',
            'REFERRAL_NOT_ELIGIBLE',
            'VALIDATION_FAILED' => ConnectorException::nonRetryable($message, $errorCode, $httpStatus),
            default => $httpStatus === 429
                ? ConnectorException::retryable($message, $errorCode ?: 'PROVIDER_UNAVAILABLE', $httpStatus)
                : ConnectorException::nonRetryable($message, $errorCode ?: 'CONNECTOR_ERROR', $httpStatus),
        };
    }

    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConnectorException::nonRetryable(
                'Failed to encode request payload as JSON: '.$e->getMessage(),
                'CONNECTOR_ERROR',
            );
        }
    }

    private function guardConfigured(): void
    {
        if (! $this->configured()) {
            throw ConnectorException::nonRetryable('Andy Core connector is not configured.', 'CONNECTOR_ERROR');
        }
    }

    private function guardMutationIdempotencyKey(string $path, ?string $idempotencyKey): void
    {
        // Read-only POST checks do not require an idempotency key.
        if (in_array($path, ['/coupons/check', '/affiliates/check'], true)) {
            return;
        }
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw ConnectorException::nonRetryable(
                'Mutation requires a non-empty idempotency key.',
                'CONNECTOR_ERROR',
            );
        }
    }

    /**
     * Returns the validated, credential-free base URL.
     * Enforces: http/https scheme, non-empty host, no userinfo, no query, no fragment.
     * Outside local/testing: https is required.
     * Throws ConnectorException::nonRetryable on any violation.
     */
    private function validatedBaseUrl(): string
    {
        $raw = rtrim(trim((string) config('services.andy_core.url')), '/');

        if ($raw === '') {
            throw ConnectorException::nonRetryable('Andy Core connector URL is not configured.', 'CONNECTOR_ERROR');
        }

        $parsed = parse_url($raw);

        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw ConnectorException::nonRetryable('Andy Core connector URL is invalid.', 'CONNECTOR_ERROR');
        }

        if (! in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw ConnectorException::nonRetryable('Andy Core connector URL must use http or https scheme.', 'CONNECTOR_ERROR');
        }

        if (! empty($parsed['user']) || ! empty($parsed['pass'])) {
            throw ConnectorException::nonRetryable('Andy Core connector URL must not contain credentials.', 'CONNECTOR_ERROR');
        }

        if (! empty($parsed['query'])) {
            throw ConnectorException::nonRetryable('Andy Core connector URL must not contain a query string.', 'CONNECTOR_ERROR');
        }

        if (! empty($parsed['fragment'])) {
            throw ConnectorException::nonRetryable('Andy Core connector URL must not contain a fragment.', 'CONNECTOR_ERROR');
        }

        if (! empty($parsed['path']) && $parsed['path'] !== '/') {
            throw ConnectorException::nonRetryable('Andy Core connector URL must be a site origin without a path.', 'CONNECTOR_ERROR');
        }

        $env = config('app.env', 'production');
        if (! in_array($env, ['local', 'testing'], true) && $parsed['scheme'] !== 'https') {
            throw ConnectorException::nonRetryable(
                'Andy Core connector requires HTTPS outside local/testing.',
                'CONNECTOR_ERROR',
            );
        }

        $url = $parsed['scheme'].'://'.$parsed['host'];
        if (! empty($parsed['port'])) {
            $url .= ':'.$parsed['port'];
        }

        return $url;
    }

    /** @return array{string,string} [timestamp, nonce] */
    private function freshNonceAndTimestamp(): array
    {
        return [
            (string) CarbonImmutable::now('UTC')->getTimestamp(),
            Str::uuid()->toString(),
        ];
    }

    private function configured(): bool
    {
        try {
            $this->validatedBaseUrl();
        } catch (ConnectorException) {
            return false;
        }

        return $this->keyId() !== '' && $this->sharedSecret() !== '';
    }

    private function connectionKey(): string
    {
        return trim((string) config('services.andy_core.connection_key', 'beyourlover.com')) ?: 'beyourlover.com';
    }

    private function keyId(): string
    {
        return trim((string) config('services.andy_core.key_id'));
    }

    private function sharedSecret(): string
    {
        return (string) config('services.andy_core.shared_secret');
    }

    private function timeout(): int
    {
        return min(30, max(1, (int) config('services.andy_core.timeout', 15)));
    }

    private function connectTimeout(): int
    {
        return min(10, max(1, (int) config('services.andy_core.connect_timeout', 5)));
    }

    private function retryTimes(): int
    {
        return min(3, max(0, (int) config('services.andy_core.retry_times', 2)));
    }

    private function shouldVerifyTls(): bool
    {
        return ! in_array(config('app.env', 'production'), ['local', 'testing'], true)
            || (bool) config('services.andy_core.verify_tls', true);
    }

    private function retrySleepMs(): int
    {
        return min(5000, max(0, (int) config('services.andy_core.retry_sleep_ms', 500)));
    }
}
