<?php

namespace Tests\Unit;

use App\Support\DataScope;
use PHPUnit\Framework\TestCase;

class DataScopeTest extends TestCase
{
    public function test_data_scope_contract_has_exact_values(): void
    {
        $this->assertSame(['own', 'assigned', 'team', 'all'], array_column(DataScope::cases(), 'value'));
    }
}
