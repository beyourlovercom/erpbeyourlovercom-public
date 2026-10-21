<?php

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class WordPressSubscriberSyncService
{
    private const int PageLimit = 100;
    private const int MaximumPages = 100;
    private const string NativeSource = 'andy_core_newsletter';

    public function __construct(private readonly WordPressSubscriberGateway $gateway, private readonly MarketingSubscriberSyncWriter $writer, private readonly SyncRunService $runs) {}

    public function sync(?Authenticatable $actor): Model
    {
        $descriptor = $this->gateway->descriptor();
        $connection = $descriptor['connection_key'] ?: null;
        $nativeEnabled = (bool) config('services.andy_core.newsletter_snapshot_enabled', false);
        $run = $this->runs->start('wordpress_subscribers_snapshot', 0, $actor, 'wordpress', $connection, [
            'domain' => 'marketing', 'resource' => 'subscribers',
            'sources' => $nativeEnabled ? ['elementor_legacy', self::NativeSource] : ['elementor_legacy'],
        ]);

        // Old ERP installs preserve their single Elementor source. Each enabled
        // source gets a fresh cursor and an independent bounded page budget.
        foreach ($nativeEnabled ? [null, self::NativeSource] : [null] as $source) {
            $cursor = null;
            $seenCursors = [];
            $pages = 0;
            try {
                do {
                    if (++$pages > self::MaximumPages) throw new \RuntimeException('Subscriber snapshot exceeded the page safety limit.');
                    $page = $this->gateway->snapshotSubscribers($cursor, null, self::PageLimit, $source);
                    if (! is_array($page['items'] ?? null) || count($page['items']) > self::PageLimit) {
                        throw new \RuntimeException('Subscriber snapshot returned an invalid item list.');
                    }
                    $sourceSite = $page['source_site'] ?? $connection;
                    if ($source === self::NativeSource && (! is_string($sourceSite) || ! preg_match('/^[a-z0-9.-]{1,190}$/D', $sourceSite))) {
                        throw new \RuntimeException('Native snapshot has invalid site attribution.');
                    }
                    foreach ($page['items'] as $item) {
                        $providerReference = is_array($item) && is_scalar($item['external_subscription_id'] ?? null) ? (string) $item['external_subscription_id'] : null;
                        $runItem = $this->runs->addItem($run, ['operation' => 'wordpress.subscriber_import', 'provider_reference' => $providerReference]);
                        $this->runs->markProcessing($runItem);
                        try {
                            if (! is_array($item)) throw new \InvalidArgumentException('Subscriber item must be an object.');
                            $this->importItem($item, $sourceSite, $source);
                            $this->runs->finishItem($runItem, SyncRunItemStatus::Succeeded, 'validated');
                        } catch (Throwable $exception) {
                            $this->runs->finishItem($runItem, SyncRunItemStatus::Failed, 'invalid_item', 'invalid_item', $exception->getMessage());
                        }
                    }
                    $next = $page['next_cursor'] ?? null;
                    if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 2048 || $next === $cursor || isset($seenCursors[$next]))) {
                        throw new \RuntimeException('Subscriber snapshot returned an invalid or repeated cursor.');
                    }
                    if ($next !== null) $seenCursors[$next] = true;
                    $cursor = $next;
                } while ($cursor !== null);
            } catch (Throwable $exception) {
                $runItem = $this->runs->addItem($run, ['operation' => 'wordpress.subscriber_snapshot', 'provider_reference' => $source ?? 'elementor_legacy']);
                $this->runs->markProcessing($runItem);
                $this->runs->finishItem($runItem, SyncRunItemStatus::Failed, 'provider_error', 'provider_error', $exception->getMessage());
            }
        }
        return $this->runs->complete($run, $actor);
    }

    private function importItem(array $item, ?string $sourceSite, ?string $source): void
    {
        $externalId = is_string($item['external_subscription_id'] ?? null) ? trim($item['external_subscription_id']) : '';
        $email = is_string($item['email'] ?? null) ? strtolower(trim($item['email'])) : '';
        $status = $item['status'] ?? null;
        if ($externalId === '' || strlen($externalId) > 255 || $email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Subscriber item requires valid identity fields.');
        }
        if (! in_array($status, ['subscribed', 'unsubscribed', 'suppressed', 'pending', 'unknown'], true)) {
            throw new \InvalidArgumentException('Subscriber item has unsupported status.');
        }
        if (isset($item['provider']) && $item['provider'] !== 'wordpress') {
            throw new \InvalidArgumentException('Subscriber item has invalid provider.');
        }
        if ($source === self::NativeSource) {
            if (! preg_match('/^andy_core_newsletter:[a-f0-9]{64}$/D', $externalId)
                || $status === 'unknown' || ($item['source_site'] ?? null) !== $sourceSite
                || ! is_string($item['status_updated_at'] ?? null) || trim($item['status_updated_at']) === '') {
                throw new \InvalidArgumentException('Native item failed source or consent validation.');
            }
        } elseif (str_starts_with($externalId, 'andy_core_newsletter:')) {
            throw new \InvalidArgumentException('Native identity cannot arrive from the legacy source.');
        }
        foreach (['status_updated_at', 'subscribed_at', 'unsubscribed_at'] as $key) {
            if (($item[$key] ?? null) === null) continue;
            $value = $item[$key];
            if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)?$/D', $value)) {
                throw new \InvalidArgumentException('Subscriber timestamp format is invalid.');
            }
            try { CarbonImmutable::parse($value, 'UTC'); }
            catch (Throwable $exception) { throw new \InvalidArgumentException('Subscriber timestamp is invalid.', 0, $exception); }
        }
        // Never derive consent from WP Users, orders or a missing snapshot status.
        $this->writer->import([...$item, 'external_subscription_id' => $externalId, 'email' => $email, 'status' => $status], $sourceSite);
    }
}
