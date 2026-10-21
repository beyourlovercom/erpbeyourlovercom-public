<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class YbyProjectSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 500, 'YBY project seeding is restricted to local/testing environments.');
        abort_unless(Schema::hasTable('business_projects'), 500, 'YBY project seeding requires the migrated schema.');

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $projectClass */
        $projectClass = 'App\\Modules\\BusinessProjects\\Infrastructure\\Models\\BusinessProject';
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $siteClass */
        $siteClass = 'App\\Modules\\BusinessProjects\\Infrastructure\\Models\\BusinessProjectSite';

        $irrigation = $projectClass::firstOrCreate(
            ['project_key' => 'yby-irrigation'],
            ['name' => 'YBY Irrigation', 'status' => 'active'],
        );

        $siteClass::firstOrCreate(
            ['domain_normalized' => 'ybyirrigation.com'],
            [
                'business_project_id' => $irrigation->id,
                'site_key' => 'ybyirrigation-com',
                'base_url' => 'https://ybyirrigation.com',
                'status' => 'active',
                'is_primary' => true,
            ],
        );

        $siteClass::firstOrCreate(
            ['domain_normalized' => 'yby-irrigation.com'],
            [
                'business_project_id' => $irrigation->id,
                'site_key' => 'yby-irrigation-com',
                'base_url' => 'https://yby-irrigation.com',
                'status' => 'active',
                'is_primary' => false,
            ],
        );
    }
}
