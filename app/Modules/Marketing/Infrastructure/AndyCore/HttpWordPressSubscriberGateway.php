<?php

namespace App\Modules\Marketing\Infrastructure\AndyCore;

use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class HttpWordPressSubscriberGateway implements WordPressSubscriberGateway
{
    private const string Namespace = '/wp-json/andy-core/v1/erp';
    private const int MaximumLimit = 100;

    public function descriptor(): array { return ['configured' => $this->configured(), 'url' => $this->baseUrl(), 'connection_key' => $this->connectionKey()]; }

    public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array
    {
        if (! $this->configured()) throw new RuntimeException('Andy Core WordPress connector is not configured.');
        if ($source !== null && $source !== 'andy_core_newsletter') throw new \InvalidArgumentException('Unsupported subscriber source.');
        // The omitted source retains the historical Elementor HMAC URL exactly.
        $query = array_filter([...($source === null ? [] : ['source' => $source]), 'cursor' => $cursor, 'updated_after' => $updatedAfter, 'limit' => min(self::MaximumLimit, max(1, $limit))], fn ($value) => $value !== null);
        $path = self::Namespace.'/snapshot/subscribers?'.http_build_query($query);
        [$timestamp, $nonce] = [(string) CarbonImmutable::now('UTC')->getTimestamp(), Str::uuid()->toString()];
        $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, $this->connectionKey(), '', hash('sha256', '')]);
        $headers = ['X-YBY-Key-Id' => $this->keyId(), 'X-YBY-Connection-Key' => $this->connectionKey(), 'X-YBY-Timestamp' => $timestamp, 'X-YBY-Nonce' => $nonce, 'X-YBY-Signature-Version' => 'v1', 'X-YBY-Signature' => hash_hmac('sha256', $canonical, $this->secret()), 'User-Agent' => 'Mozilla/5.0 BYL-ERP-Marketing-WordPress-Connector/1.0'];
        $request = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withOptions(['verify' => true]);
        if (! $this->shouldVerifyTls()) {
            $request->withoutVerifying();
        }
        try { $response = $request->get($this->baseUrl().$path); }
        catch (ConnectionException $exception) { throw new RuntimeException('Andy Core WordPress connector unavailable.', 0, $exception); }
        if ($response->redirect() || $response->serverError() || $response->clientError()) throw new RuntimeException('Andy Core WordPress connector returned HTTP '.$response->status().'.');
        $raw = $response->json();
        if (! is_array($raw) || ($raw['ok'] ?? null) !== true || ($raw['contract_version'] ?? null) !== '1' || ! is_string($raw['request_id'] ?? null) || $raw['request_id'] === '' || ($raw['connection_key'] ?? null) !== $this->connectionKey()) throw new RuntimeException('Andy Core WordPress connector returned an invalid response envelope.');
        $data = $raw['data'] ?? [];
        if (! is_array($data) || ! is_array($data['items'] ?? null)) throw new RuntimeException('Andy Core WordPress connector returned invalid subscriber data.');
        if (count($data['items']) > min(self::MaximumLimit, max(1, $limit))) throw new RuntimeException('Andy Core snapshot exceeded requested page size.');
        $next = $data['next_cursor'] ?? null;
        if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 2048)) throw new RuntimeException('Andy Core returned an invalid snapshot cursor.');
        $site = $data['source_site'] ?? $this->connectionKey();
        if (! is_string($site) || ($source !== null && ! preg_match('/^[a-z0-9.-]{1,190}$/D', $site))) throw new RuntimeException('Andy Core returned an invalid source site.');
        return ['items' => array_values($data['items']), 'next_cursor' => $next, 'source_site' => $site, 'request_id' => $raw['request_id']];
    }

    private function configured(): bool { return $this->baseUrl() !== '' && $this->keyId() !== '' && $this->secret() !== ''; }
    private function baseUrl(): string
    {
        $raw = rtrim(trim((string) config('services.andy_core.url')), '/'); if ($raw === '') return '';
        $parsed = parse_url($raw);
        if ($parsed === false || empty($parsed['host']) || ! in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || ! empty($parsed['user']) || ! empty($parsed['pass']) || ! empty($parsed['query']) || ! empty($parsed['fragment']) || (! empty($parsed['path']) && $parsed['path'] !== '/') || (! in_array(config('app.env', 'production'), ['local', 'testing'], true) && ($parsed['scheme'] ?? '') !== 'https')) return '';
        return $parsed['scheme'].'://'.$parsed['host'].(! empty($parsed['port']) ? ':'.$parsed['port'] : '');
    }
    private function connectionKey(): string { return trim((string) config('services.andy_core.connection_key', 'beyourlover.com')) ?: 'beyourlover.com'; }
    private function keyId(): string { return trim((string) config('services.andy_core.key_id')); }
    private function secret(): string { return (string) config('services.andy_core.shared_secret'); }
    private function shouldVerifyTls(): bool
    {
        return ! in_array(config('app.env', 'production'), ['local', 'testing'], true)
            || (bool) config('services.andy_core.verify_tls', true);
    }
}
