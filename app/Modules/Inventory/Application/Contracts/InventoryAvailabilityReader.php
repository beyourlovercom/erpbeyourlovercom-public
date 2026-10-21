<?php

namespace App\Modules\Inventory\Application\Contracts;

interface InventoryAvailabilityReader
{
    /** @return array<int, array{id:int,slug:string,name:string,code:string,country_code:?string,priority:int,inventory_state:string,fulfillment_available_on:?string,can_auto_allocate:bool,service_regions:array<int,array{country_code:string,priority:int}>}> */
    public function activeWarehouses(): array;

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<int, array{warehouse_id:int,warehouse_slug:string,warehouse_name:string,inventory_state:string,can_auto_allocate:bool,quantity_on_hand:?int,quantity_reserved:?int,quantity_available:?int,expected_restock_qty:?int}>>
     */
    public function forProductIds(array $productIds): array;
}
