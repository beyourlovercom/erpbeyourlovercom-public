<?php

namespace App\Modules\Traffic\Infrastructure\GoogleAds;

use App\Modules\Traffic\Application\Contracts\GoogleAdsReadClient;
use App\Modules\Traffic\Application\Contracts\InquiryMetricSignalReader;
use App\Modules\Traffic\Application\Exceptions\GoogleAdsReadException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class HttpGoogleAdsReadClient implements GoogleAdsReadClient, InquiryMetricSignalReader
{
    private ?string $accessToken = null;

    public function fetchAccount(string $externalAccountId): array
    {
        $customerId = $this->normalizeCustomerId($externalAccountId);
        $rows = $this->searchStream($customerId, <<<'GAQL'
SELECT
  customer.id,
  customer.descriptive_name,
  customer.currency_code,
  customer.time_zone
FROM customer
LIMIT 1
GAQL);

        $row = $rows[0] ?? null;
        $customer = is_array($row) ? ($row['customer'] ?? null) : null;
        if (! is_array($customer)) {
            throw new GoogleAdsReadException('account_not_found', 'Google Ads account metadata was not returned.');
        }

        $returnedId = $this->normalizeCustomerId((string) ($customer['id'] ?? ''));
        if ($returnedId !== $customerId) {
            throw new GoogleAdsReadException('source_account_mismatch', 'Google Ads returned a different customer account.');
        }

        return [
            'external_account_id' => $externalAccountId,
            'name' => $this->nullableString($customer['descriptiveName'] ?? null),
            'currency_code' => $this->nullableString($customer['currencyCode'] ?? null),
            'account_timezone' => $this->nullableString($customer['timeZone'] ?? null),
        ];
    }

    public function fetchCampaignDailyMetrics(string $externalAccountId, string $dateFrom, string $dateTo): iterable
    {
        $customerId = $this->normalizeCustomerId($externalAccountId);
        $query = sprintf(<<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.advertising_channel_type,
  segments.date,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM campaign
WHERE segments.date BETWEEN '%s' AND '%s'
ORDER BY segments.date, campaign.id
GAQL, $dateFrom, $dateTo);

        foreach ($this->searchStream($customerId, $query) as $row) {
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $segments = is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

            yield [
                'external_campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => (string) ($campaign['name'] ?? ''),
                'campaign_status' => $this->nullableString($campaign['status'] ?? null),
                'advertising_channel_type' => $this->nullableString($campaign['advertisingChannelType'] ?? null),
                'metric_date' => (string) ($segments['date'] ?? ''),
                'impressions' => $this->nonNegativeIntegerMetric($metrics['impressions'] ?? 0),
                'clicks' => $this->nonNegativeIntegerMetric($metrics['clicks'] ?? 0),
                'cost_micros' => $this->nonNegativeIntegerMetric($metrics['costMicros'] ?? 0),
                'provider_conversions' => $this->nonNegativeNumericMetric($metrics['conversions'] ?? 0),
            ];
        }
    }

    public function fetchDailySignals(string $externalAccountId, string $dateFrom, string $dateTo): iterable
    {
        $customerId = $this->normalizeCustomerId($externalAccountId);
        $configured = $this->inquiryActions();
        $actionRows = $this->searchStream($customerId, <<<'GAQL'
SELECT
  conversion_action.resource_name,
  conversion_action.name,
  conversion_action.status
FROM conversion_action
ORDER BY conversion_action.id
GAQL);

        $resourceByName = [];
        foreach ($actionRows as $row) {
            $action = is_array($row['conversionAction'] ?? null) ? $row['conversionAction'] : [];
            $name = trim((string) ($action['name'] ?? ''));
            $resource = trim((string) ($action['resourceName'] ?? ''));
            if ($name !== '' && $resource !== '' && ($action['status'] ?? null) !== 'REMOVED') {
                $resourceByName[$name] = $resource;
            }
        }

        $sourceByResource = [];
        foreach ($configured as $sourceType => $actionName) {
            $resource = $resourceByName[$actionName] ?? null;
            if ($resource === null) {
                throw new GoogleAdsReadException('inquiry_action_not_found', 'Configured Google Ads inquiry conversion action was not returned.');
            }
            $sourceByResource[$resource] = $sourceType;
        }

        $signals = [];
        $cursor = new \DateTimeImmutable($dateFrom);
        $end = new \DateTimeImmutable($dateTo);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            foreach ($configured as $sourceType => $_) {
                $signals[$date.'|'.$sourceType] = ['metric_date' => $date, 'source_type' => $sourceType, 'inquiries' => 0];
            }
            $cursor = $cursor->modify('+1 day');
        }

        $query = sprintf(<<<'GAQL'
SELECT
  segments.date,
  segments.conversion_action,
  metrics.conversions
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
ORDER BY segments.date, segments.conversion_action
GAQL, $dateFrom, $dateTo);

        foreach ($this->searchStream($customerId, $query) as $row) {
            $segments = is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $sourceType = $sourceByResource[trim((string) ($segments['conversionAction'] ?? ''))] ?? null;
            if ($sourceType === null) continue;
            $date = (string) ($segments['date'] ?? '');
            $key = $date.'|'.$sourceType;
            if (! isset($signals[$key])) {
                throw new GoogleAdsReadException('provider_invalid_response', 'Google Ads returned an inquiry signal outside the requested date range.');
            }
            $signals[$key]['inquiries'] += $this->nonNegativeWholeMetric($metrics['conversions'] ?? 0);
        }

        ksort($signals);
        return array_values($signals);
    }

    /** @return array<int, array<string, mixed>> */
    private function searchStream(string $customerId, string $query): array
    {
        $response = $this->client()->post(
            rtrim((string) config('services.google_ads.base_url', 'https://googleads.googleapis.com'), '/')
                .'/'.(string) config('services.google_ads.api_version', 'v25')
                .'/customers/'.$customerId.'/googleAds:searchStream',
            ['query' => $query],
        );

        if (! $response->successful()) {
            throw new GoogleAdsReadException('provider_error', 'Google Ads read request failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new GoogleAdsReadException('provider_invalid_response', 'Google Ads returned an invalid JSON response.');
        }

        $chunks = array_is_list($payload) ? $payload : [$payload];
        $rows = [];
        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            $results = $chunk['results'] ?? [];
            if (! is_array($results)) {
                continue;
            }
            foreach ($results as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private function client(): PendingRequest
    {
        $config = $this->credentials();
        $headers = ['Authorization' => 'Bearer '.$this->token(), 'Accept' => 'application/json'];

        if ($config['developer_token'] !== '') {
            $headers['developer-token'] = $config['developer_token'];
        }
        if ($config['login_customer_id'] !== '') {
            $headers['login-customer-id'] = $this->normalizeCustomerId($config['login_customer_id']);
        }

        return Http::withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout($config['timeout']);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $config = $this->credentials();
        $response = Http::asForm()->timeout($config['timeout'])->post(
            (string) config('services.google_ads.oauth_url', 'https://oauth2.googleapis.com/token'),
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $config['refresh_token'],
                'grant_type' => 'refresh_token',
            ],
        );

        if (! $response->successful()) {
            throw new GoogleAdsReadException('authentication_failed', 'Google Ads OAuth token refresh failed with HTTP '.$response->status().'.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new GoogleAdsReadException('authentication_failed', 'Google Ads OAuth response did not include an access token.');
        }

        return $this->accessToken = $token;
    }

    /** @return array<string, string> */
    private function inquiryActions(): array
    {
        $actions = config('services.google_ads.inquiry_actions', []);
        if (! is_array($actions) || $actions === []) {
            throw new GoogleAdsReadException('inquiry_action_config_invalid', 'Google Ads inquiry conversion actions are not configured.');
        }
        $normalized = [];
        foreach ($actions as $sourceType => $actionName) {
            $sourceType = trim((string) $sourceType);
            $actionName = trim((string) $actionName);
            if (preg_match('/^[a-z0-9_]{1,32}$/D', $sourceType) !== 1 || $actionName === '' || in_array($actionName, $normalized, true)) {
                throw new GoogleAdsReadException('inquiry_action_config_invalid', 'Google Ads inquiry conversion action configuration is invalid.');
            }
            $normalized[$sourceType] = $actionName;
        }
        ksort($normalized);
        return $normalized;
    }

    private function nonNegativeWholeMetric(mixed $value): int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^\d+(?:\.0+)?$/D', $value) === 1) {
            $numeric = (float) $value;
            if ($numeric <= PHP_INT_MAX) return (int) $numeric;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) return (int) $value;
        throw new GoogleAdsReadException('fractional_inquiry_signal', 'Google Ads inquiry conversion count must be a whole non-negative number.');
    }

    /** @return array{client_id:string,client_secret:string,refresh_token:string,developer_token:string,login_customer_id:string,timeout:int} */
    private function credentials(): array
    {
        $config = [
            'client_id' => trim((string) config('services.google_ads.client_id', '')),
            'client_secret' => trim((string) config('services.google_ads.client_secret', '')),
            'refresh_token' => trim((string) config('services.google_ads.refresh_token', '')),
            'developer_token' => trim((string) config('services.google_ads.developer_token', '')),
            'login_customer_id' => trim((string) config('services.google_ads.login_customer_id', '')),
            'timeout' => max(1, (int) config('services.google_ads.timeout', 20)),
        ];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $required) {
            if ($config[$required] === '') {
                throw new GoogleAdsReadException('credentials_missing', 'Google Ads OAuth credentials are not configured.');
            }
        }

        return $config;
    }

    private function normalizeCustomerId(string $value): string
    {
        $id = preg_replace('/\D+/', '', $value) ?? '';
        if ($id === '') {
            throw new GoogleAdsReadException('invalid_account_id', 'Google Ads customer ID must contain digits.');
        }

        return $id;
    }

    private function nonNegativeIntegerMetric(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $max = (string) PHP_INT_MAX;
            if (strlen($normalized) < strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) <= 0)) {
                return (int) $normalized;
            }
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value && $value <= PHP_INT_MAX) {
            return (int) $value;
        }

        throw new GoogleAdsReadException('provider_invalid_response', 'Google Ads returned an invalid integer metric.');
    }

    private function nonNegativeNumericMetric(mixed $value): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new GoogleAdsReadException('provider_invalid_response', 'Google Ads returned an invalid conversion metric.');
        }

        return (float) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
