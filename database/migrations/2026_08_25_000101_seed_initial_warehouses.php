<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now=now();
        $rows=[
            ['slug'=>'us-la','code'=>'US-LA','name'=>'美东洛杉矶仓','priority'=>10],
            ['slug'=>'us-ny','code'=>'US-NY','name'=>'美西纽约仓','priority'=>20],
            ['slug'=>'de','code'=>'DE','name'=>'德国仓','priority'=>30],
            ['slug'=>'ca','code'=>'CA','name'=>'加拿大仓库','priority'=>40],
            ['slug'=>'factory','code'=>'FACTORY','name'=>'中国工厂','priority'=>50],
            ['slug'=>'007','code'=>'007','name'=>'007本地仓库','priority'=>60],
        ];
        foreach($rows as $row) DB::table('warehouses')->updateOrInsert(['slug'=>$row['slug']],[...$row,'type'=>'warehouse','is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void { DB::table('warehouses')->whereIn('slug',['us-la','us-ny','de','ca','factory','007'])->whereNotIn('id',DB::table('warehouse_inventory')->select('warehouse_id'))->delete(); }
};
