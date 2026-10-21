<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\DeliveryEvidenceGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AfterShipDeliveryEvidenceGateway implements DeliveryEvidenceGateway
{
    public function descriptor(): array
    {
        return [
            'configured' => $this->configured(),
            'source' => 'aftership_tracking_api',
            'version' => (string) config('services.aftership_tracking.version', '2026-07'),
        ];
    }

    public function inspect(string $slug, string $trackingNumber): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('AfterShip Tracking API is not configured.');
        }

        $payload = $this->client()->get($this->baseUrl().'/trackings', [
            'tracking_numbers' => $trackingNumber,
            'slug' => $slug,
            'limit' => 10,
        ])->throw()->json();
        $trackings = data_get($payload, 'data.trackings', []);
        if (! is_array($trackings)) {
            throw new RuntimeException('AfterShip Tracking API response is invalid.');
        }

        $matches = array_values(array_filter($trackings, static fn ($tracking): bool => is_array($tracking)
            && trim((string) ($tracking['tracking_number'] ?? '')) === $trackingNumber
            && trim((string) ($tracking['slug'] ?? '')) === $slug));
        if ($matches === []) {
            return $this->emptyEvidence($slug, $trackingNumber);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('AfterShip returned multiple exact tracking matches.');
        }

        $tracking = $matches[0];
        $checkpoints = is_array($tracking['checkpoints'] ?? null) ? array_values($tracking['checkpoints']) : [];
        $checkpoint = $checkpoints !== [] && is_array(end($checkpoints)) ? end($checkpoints) : null;

        return [
            'found' => true,
            'tracking_number' => $trackingNumber,
            'slug' => $slug,
            'provider_reference' => $this->nullableString($tracking['id'] ?? null),
            'tag' => $this->nullableString($tracking['tag'] ?? null),
            'subtag' => $this->nullableString($tracking['subtag'] ?? null),
            'subtag_message' => $this->nullableString($tracking['subtag_message'] ?? null),
            'provider_updated_at' => $this->nullableString($tracking['updated_at'] ?? null),
            'checkpoint' => $this->boundedCheckpoint($checkpoint),
        ];
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['as-api-key' => (string) config('services.aftership_tracking.api_key')])
            ->timeout(max(1, (int) config('services.aftership_tracking.timeout', 15)));
    }

    private function configured(): bool
    {
        return trim((string) config('services.aftership_tracking.api_key')) !== '';
    }

    private function baseUrl(): string
    {
        $root = rtrim((string) config('services.aftership_tracking.base_url', 'https://api.aftership.com/tracking'), '/');
        $version = trim((string) config('services.aftership_tracking.version', '2026-07'));

        return $root.'/'.$version;
    }

    /** @return array<string,mixed>|null */
    private function boundedCheckpoint(?array $checkpoint): ?array
    {
        if ($checkpoint === null) {
            return null;
        }

        return array_filter([
            'tag' => $this->nullableString($checkpoint['tag'] ?? null),
            'subtag' => $this->nullableString($checkpoint['subtag'] ?? null),
            'subtag_message' => $this->nullableString($checkpoint['subtag_message'] ?? null),
            'message' => $this->nullableString($checkpoint['message'] ?? null),
            'checkpoint_time' => $this->nullableString($checkpoint['checkpoint_time'] ?? null),
            'source' => $this->nullableString($checkpoint['source'] ?? null),
        ], static fn ($value): bool => $value !== null);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /** @return array{found:false,tracking_number:string,slug:string,provider_reference:null,tag:null,subtag:null,subtag_message:null,provider_updated_at:null,checkpoint:null} */
    private function emptyEvidence(string $slug, string $trackingNumber): array
    {
        return [
            'found' => false,
            'tracking_number' => $trackingNumber,
            'slug' => $slug,
            'provider_reference' => null,
            'tag' => null,
            'subtag' => null,
            'subtag_message' => null,
            'provider_updated_at' => null,
            'checkpoint' => null,
        ];
    }
}
