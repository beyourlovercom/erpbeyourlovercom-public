<?php

namespace Tests\Feature;

use App\Modules\Orders\Application\OrderIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderIdentityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_and_custom_prefix_generate_canonical_ids(): void
    {
        $service = app(OrderIdentityService::class);

        $this->assertSame('007erp', $service->prefix());
        $this->assertSame('007erp-0901-c3721', $service->generate('c', 3721, '2026-09-01'));

        $service->updatePrefix(' 007 ', null);

        $this->assertSame('007', $service->prefix());
        $this->assertSame('007-0901-c3721', $service->generate('c', 3721, '2026-09-01'));
        $this->assertSame('007-0901-k381', $service->generate('k', 381, '2026-09-01'));
    }


    public function test_woo_customer_identity_uses_customer_marker_plus_woo_order_number(): void
    {
        $service = app(OrderIdentityService::class);
        $service->updatePrefix('007', null);

        $this->assertSame('007-0910-c40414', $service->previewWooCustomer('40414', '50123', '2026-09-10'));
        $this->assertSame('007-0910-c40414', $service->previewWooCustomer('40414-VA617A-gift', '50123', '2026-09-10'));
        $this->assertSame('007-0910-c50123', $service->previewWooCustomer('WC-40414', '50123', '2026-09-10'));
    }

    public function test_customer_sequence_is_global_and_prefix_changes_do_not_reset_it(): void
    {
        $service = app(OrderIdentityService::class);
        $setting = $service->setting();
        $setting->next_customer_number = 3721;
        $setting->save();

        $service->updatePrefix('007', null);
        $this->assertSame('007-0901-c3721', $service->allocateCustomer('2026-09-01'));
        $this->assertSame('007-0901-c3722', $service->allocateCustomer('2026-09-01'));

        $service->updatePrefix('yby', null);
        $this->assertSame('yby-0901-c3723', $service->allocateCustomer('2026-09-01'));
    }

    public function test_suffix_is_only_used_when_explicitly_supplied(): void
    {
        $service = app(OrderIdentityService::class);

        $this->assertSame('007erp-0901-k381A', $service->generate('k', 381, '2026-09-01', 'A'));
    }
}
