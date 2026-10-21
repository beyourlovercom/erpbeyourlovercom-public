<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\DeliveryEvidenceGateway;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AfterShipBrandedPageDeliveryEvidenceGateway implements DeliveryEvidenceGateway
{
    public function descriptor(): array
    {
        return [
            'configured' => $this->baseUrl() !== '',
            'source' => 'aftership_branded_page',
            'version' => (string) config('services.aftership_branded_page.parser_version', 'semantic-html-v1'),
        ];
    }

    public function inspect(string $slug, string $trackingNumber): array
    {
        $trackingNumber = trim($trackingNumber);
        if ($this->baseUrl() === '' || $trackingNumber === '') {
            throw new RuntimeException('AfterShip branded tracking page is not configured.');
        }

        $url = $this->trackingUrl($trackingNumber);
        $response = Http::accept('text/html')
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BYL-ERP-DeliveryEvidence/1.0)'])
            ->timeout(max(1, (int) config('services.aftership_branded_page.timeout', 15)))
            ->get($url, ['lang' => 'en']);
        if ($response->status() === 404) {
            return $this->emptyEvidence($slug, $trackingNumber, $url);
        }
        $response->throw();
        $html = (string) $response->body();
        if ($html === '' || ! str_contains($html, $trackingNumber)) {
            return $this->emptyEvidence($slug, $trackingNumber, $url);
        }

        return $this->parse($html, $slug, $trackingNumber, $url);
    }

    /** @return array<string,mixed> */
    public function parse(string $html, string $slug, string $trackingNumber, ?string $providerReference = null): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('AfterShip branded page HTML is invalid.');
        }
        $xpath = new DOMXPath($document);

        $pageHeading = $this->firstAriaLabel($xpath, "//*[@role='heading' and @aria-level='2' and @aria-label]");
        $checkpointTitle = $this->firstAriaLabel($xpath, "//*[@role='heading' and @aria-level='3' and @aria-label]");
        [$tag, $rawStage] = $this->canonicalStage($checkpointTitle, $pageHeading);
        if ($tag === null || $rawStage === null) {
            throw new RuntimeException('AfterShip branded page status is unsupported or ambiguous.');
        }

        $groups = $xpath->query("//*[@role='group' and @aria-label]");
        $labels = [];
        if ($groups !== false) {
            foreach ($groups as $node) {
                if ($node instanceof DOMElement) {
                    $label = trim($node->getAttribute('aria-label'));
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
            }
        }
        $time = collect($labels)->first(fn (string $label): bool => preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+\d{4}\b/i', $label) === 1);
        $location = collect($labels)->first(fn (string $label): bool => str_contains($label, ' · '));
        $caption = collect($labels)->first(function (string $label) use ($time, $location, $trackingNumber): bool {
            return $label !== $time && $label !== $location && $label !== $trackingNumber
                && preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+\d{4}\b/i', $label) !== 1;
        });
        $carrier = null;
        if (is_string($location) && str_contains($location, ' · ')) {
            $parts = explode(' · ', $location);
            $carrier = trim((string) end($parts));
        }
        if ($carrier === null || $carrier === '') {
            $carrier = collect($labels)->first(fn (string $label): bool => in_array(strtoupper($label), ['4PX', 'USPS', 'DHL', 'UPS', 'FEDEX'], true));
        }

        return [
            'found' => true,
            'tracking_number' => $trackingNumber,
            'slug' => $slug,
            'provider_reference' => $providerReference,
            'tag' => $tag,
            'subtag' => $rawStage,
            'subtag_message' => $pageHeading ?: $caption,
            'provider_updated_at' => is_string($time) ? trim($time) : null,
            'checkpoint' => array_filter([
                'tag' => $tag,
                'subtag' => $rawStage,
                'subtag_message' => $caption,
                'message' => $caption,
                'checkpoint_time' => is_string($time) ? trim($time) : null,
                'source' => is_string($carrier) ? $carrier : null,
                'page_heading' => $pageHeading,
                'parser_version' => (string) config('services.aftership_branded_page.parser_version', 'semantic-html-v1'),
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function canonicalStage(?string $checkpointTitle, ?string $pageHeading): array
    {
        $value = strtolower(trim((string) ($checkpointTitle ?: $pageHeading)));
        if ($value === '') {
            return [null, null];
        }
        if (str_contains($value, 'delivered')) {
            return ['Delivered', 'delivered'];
        }
        if (str_contains($value, 'out for delivery')) {
            return ['OutForDelivery', 'out_for_delivery'];
        }
        if (str_contains($value, 'in transit') || str_contains($value, 'on the way')) {
            return ['InTransit', 'in_transit'];
        }
        if (str_contains($value, 'shipping soon') || str_contains($value, 'ship soon') || str_contains($value, 'information received')) {
            return ['InfoReceived', 'info_received'];
        }
        if (str_contains($value, 'failed attempt')) {
            return ['AttemptFail', 'failed_attempt'];
        }
        if (str_contains($value, 'exception')) {
            return ['Exception', 'exception'];
        }
        if (str_contains($value, 'expired')) {
            return ['Expired', 'expired'];
        }

        return [null, null];
    }

    private function firstAriaLabel(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $node = $nodes->item(0);
        if (! $node instanceof DOMElement) {
            return null;
        }
        $value = trim($node->getAttribute('aria-label'));

        return $value === '' ? null : $value;
    }

    private function trackingUrl(string $trackingNumber): string
    {
        return $this->baseUrl().'/'.rawurlencode($trackingNumber);
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.aftership_branded_page.base_url', 'https://beyourlover.aftership.com')), '/');
    }

    /** @return array<string,mixed> */
    private function emptyEvidence(string $slug, string $trackingNumber, string $providerReference): array
    {
        return [
            'found' => false, 'tracking_number' => $trackingNumber, 'slug' => $slug,
            'provider_reference' => $providerReference, 'tag' => null, 'subtag' => null,
            'subtag_message' => null, 'provider_updated_at' => null, 'checkpoint' => null,
        ];
    }
}
