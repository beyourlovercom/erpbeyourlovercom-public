<?php

namespace App\Modules\Sync\Application;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use DateTimeImmutable;
use RuntimeException;

final class EmailTemplateSnapshotReader
{
    public const string Resource = 'email-templates';
    public const string ResourceContractVersion = 'email-os-published-v1';

    private const int MaxPages = 10;

    private const array Fields = [
        'template_key',
        'provider',
        'label',
        'status',
        'runtime_enabled',
        'published_version',
        'content_hash_sha256',
        'published_at',
    ];

    public function __construct(private readonly AffiliateWpGateway $gateway) {}

    public function read(): array
    {
        $items = [];
        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $pages++;
            if ($pages > self::MaxPages) {
                throw new RuntimeException('Email template snapshot exceeded the bounded page limit.');
            }

            $response = $this->gateway->snapshot(self::Resource, $cursor, null);
            if (! $response->ok || $response->contractVersion !== '1') {
                throw new RuntimeException('Email template snapshot outer contract is invalid.');
            }

            $data = $response->data;
            if (! is_array($data) || ($data['resource_contract_version'] ?? null) !== self::ResourceContractVersion) {
                throw new RuntimeException('Email template snapshot resource contract is unsupported.');
            }

            $rows = $data['items'] ?? null;
            if (! is_array($rows)) {
                throw new RuntimeException('Email template snapshot items are invalid.');
            }

            foreach ($rows as $row) {
                $item = $this->validateItem($row);
                $key = $item['template_key'];
                if (isset($seen[$key])) {
                    throw new RuntimeException('Email template snapshot contains duplicate template_key.');
                }
                $seen[$key] = true;
                $items[] = $item;
            }

            $next = $data['next_cursor'] ?? null;
            if ($next !== null && (! is_string($next) || $next === '')) {
                throw new RuntimeException('Email template snapshot cursor is invalid.');
            }
            if ($next !== null && $next === $cursor) {
                throw new RuntimeException('Email template snapshot cursor did not advance.');
            }
            $cursor = $next;
        } while ($cursor !== null);

        usort($items, fn (array $a, array $b): int => [$a['provider'], $a['label']] <=> [$b['provider'], $b['label']]);

        return [
            'status' => 'ready',
            'read_only' => true,
            'resource_contract_version' => self::ResourceContractVersion,
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function validateItem(mixed $row): array
    {
        if (! is_array($row)) {
            throw new RuntimeException('Email template snapshot row is invalid.');
        }

        $keys = array_keys($row);
        sort($keys);
        $expected = self::Fields;
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Email template snapshot row schema drift detected.');
        }

        $provider = (string) $row['provider'];
        if (! in_array($provider, ['wordpress', 'andy_core'], true)) {
            throw new RuntimeException('Email template snapshot provider is not native.');
        }
        if ((string) $row['status'] !== 'published') {
            throw new RuntimeException('Email template snapshot status is not published.');
        }
        if (! is_bool($row['runtime_enabled'])) {
            throw new RuntimeException('Email template snapshot runtime_enabled is invalid.');
        }
        if (! is_int($row['published_version']) || $row['published_version'] < 1) {
            throw new RuntimeException('Email template snapshot published_version is invalid.');
        }

        $templateKey = (string) $row['template_key'];
        $label = (string) $row['label'];
        $hash = (string) $row['content_hash_sha256'];
        $publishedAt = (string) $row['published_at'];
        if ($templateKey === '' || $label === '') {
            throw new RuntimeException('Email template snapshot identity is incomplete.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Email template snapshot SHA-256 is invalid.');
        }
        try {
            new DateTimeImmutable($publishedAt);
        } catch (\Throwable) {
            throw new RuntimeException('Email template snapshot published_at is invalid.');
        }

        return [
            'template_key' => $templateKey,
            'provider' => $provider,
            'label' => $label,
            'status' => 'published',
            'runtime_enabled' => $row['runtime_enabled'],
            'published_version' => $row['published_version'],
            'content_hash_sha256' => $hash,
            'published_at' => $publishedAt,
        ];
    }
}
