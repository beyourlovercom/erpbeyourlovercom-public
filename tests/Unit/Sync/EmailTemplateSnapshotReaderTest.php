<?php

namespace Tests\Unit\Sync;

use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Sync\Application\EmailTemplateSnapshotReader;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailTemplateSnapshotReaderTest extends TestCase
{
    public function test_reads_only_frozen_published_native_metadata_across_pages(): void
    {
        $gateway = new EmailTemplateSnapshotGateway([
            null => $this->page([
                $this->item('wordpress:reset_password', 'wordpress', 'Reset Password', 3, 'a'),
            ], 'page-2'),
            'page-2' => $this->page([
                $this->item('andy_core:inquiry_internal_notification', 'andy_core', 'Inquiry Internal Notification', 2, 'b'),
            ]),
        ]);

        $result = (new EmailTemplateSnapshotReader($gateway))->read();

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['read_only']);
        self::assertSame('email-os-published-v1', $result['resource_contract_version']);
        self::assertSame(2, $result['count']);
        self::assertSame(
            ['andy_core:inquiry_internal_notification', 'wordpress:reset_password'],
            array_column($result['items'], 'template_key'),
        );
        foreach ($result['items'] as $item) {
            self::assertSame([
                'template_key', 'provider', 'label', 'status', 'runtime_enabled',
                'published_version', 'content_hash_sha256', 'published_at',
            ], array_keys($item));
            self::assertSame('published', $item['status']);
        }
        self::assertSame([
            ['email-templates', null, null],
            ['email-templates', 'page-2', null],
        ], $gateway->calls);
    }

    public function test_rejects_content_payload_schema_drift(): void
    {
        $row = $this->item('wordpress:reset_password', 'wordpress', 'Reset Password', 1, 'c');
        $row['body'] = '<p>forbidden</p>';
        $reader = new EmailTemplateSnapshotReader(new EmailTemplateSnapshotGateway([null => $this->page([$row])]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema drift');
        $reader->read();
    }
    public function test_rejects_woo_or_non_published_rows(): void
    {
        $woo = $this->item('woocommerce:customer_completed_order', 'woocommerce', 'Completed Order', 1, 'd');
        $reader = new EmailTemplateSnapshotReader(new EmailTemplateSnapshotGateway([null => $this->page([$woo])]));
        try {
            $reader->read();
            self::fail('Woo row must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('provider is not native', $exception->getMessage());
        }

        $draft = $this->item('wordpress:new_user', 'wordpress', 'New User', 1, 'e');
        $draft['status'] = 'draft';
        $reader = new EmailTemplateSnapshotReader(new EmailTemplateSnapshotGateway([null => $this->page([$draft])]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status is not published');
        $reader->read();
    }

    public function test_reader_source_has_no_persistence_or_send_path(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Modules/Sync/Application/EmailTemplateSnapshotReader.php');
        self::assertIsString($source);
        foreach (['DB::', '->save(', '->insert(', '->update(', 'Http::post', 'Mail::', 'wp_mail'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
    private function item(string $key, string $provider, string $label, int $version, string $hashChar): array
    {
        return [
            'template_key' => $key,
            'provider' => $provider,
            'label' => $label,
            'status' => 'published',
            'runtime_enabled' => true,
            'published_version' => $version,
            'content_hash_sha256' => str_repeat($hashChar, 64),
            'published_at' => '2026-09-15 17:00:00',
        ];
    }

    private function page(array $items, ?string $nextCursor = null): array
    {
        return [
            'resource_contract_version' => EmailTemplateSnapshotReader::ResourceContractVersion,
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }
}

final class EmailTemplateSnapshotGateway implements AffiliateWpGateway
{
    public array $calls = [];

    public function __construct(private readonly array $pages) {}
    public function descriptor(): array
    {
        return ['configured' => true, 'url' => 'https://wp.test', 'connection_key' => 'beyourlover.com', 'key_id' => 'test'];
    }

    public function health(): ConnectorResponse
    {
        throw new LogicException('not used');
    }

    public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse
    {
        $this->calls[] = [$resource, $cursor, $updatedAfter];
        $key = $cursor ?? '';
        $page = $this->pages[$cursor] ?? $this->pages[$key] ?? null;
        if (! is_array($page)) {
            throw new LogicException('unexpected cursor');
        }

        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'test-request',
            'connection_key' => 'beyourlover.com',
            'data' => $page,
        ]);
    }

    public function checkAffiliateIdentity(string $email): ConnectorResponse { throw new LogicException('not used'); }
    public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse { throw new LogicException('not used'); }
    public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse { throw new LogicException('not used'); }
    public function updateWordpressPassword(int $wordpressUserId, array $payload, string $idempotencyKey): ConnectorResponse { throw new LogicException('not used'); }
    public function checkCoupon(string $code): ConnectorResponse { throw new LogicException('not used'); }
    public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse { throw new LogicException('not used'); }
    public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse { throw new LogicException('not used'); }
}
