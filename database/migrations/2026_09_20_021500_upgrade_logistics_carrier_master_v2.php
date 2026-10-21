<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_carriers', function (Blueprint $table): void {
            $table->string('name_en', 128)->nullable()->after('name');
            $table->string('name_zh', 128)->nullable()->after('name_en');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });

        Schema::create('logistics_carrier_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carrier_id')->constrained('logistics_carriers')->cascadeOnDelete();
            $table->string('service_key', 80);
            $table->string('service_value', 128);
            $table->string('name_en', 128)->nullable();
            $table->string('name_zh', 128)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['carrier_id', 'service_key'], 'log_carrier_services_carrier_key_unique');
            $table->unique(['carrier_id', 'service_value'], 'log_carrier_services_carrier_value_unique');
        });

        Schema::table('logistics_carriers', function (Blueprint $table): void {
            $table->foreignId('default_service_id')->nullable()->after('name_zh')->constrained('logistics_carrier_services')->nullOnDelete();
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('carrier_service_id')->nullable()->after('service')->constrained('logistics_carrier_services')->nullOnDelete();
        });

        $catalog = [
            'USPS' => [
                'en' => 'USPS', 'zh' => '美国邮政', 'default' => 'priority_mail',
                'services' => [
                    ['priority_mail_express', 'USPS Priority Mail Express', 'Priority Mail Express', '快件'],
                    ['priority_mail', 'USPS Priority Mail', 'Priority Mail', '优先件'],
                    ['ground_advantage', 'USPS Ground Advantage', 'Ground Advantage / First-Class', '平邮'],
                ],
            ],
            '4PX' => [
                'en' => '4PX', 'zh' => '速达', 'default' => 'sd_economy_registered_battery',
                'services' => [
                    ['sd_economy_registered_battery', 'SD经济挂号-带电', 'Economy Registered - Battery', 'SD经济挂号-带电'],
                    ['sd_standard_registered_battery', 'SD标准挂号-带电', 'Standard Registered - Battery', 'SD标准挂号-带电'],
                    ['sd_standard_registered_general', 'SD标准挂号-普货', 'Standard Registered - General', 'SD标准挂号-普货'],
                ],
            ],
            'DHL' => [
                'en' => 'DHL', 'zh' => '敦豪', 'default' => 'paket_international',
                'services' => [
                    ['paket_domestic', 'DHL PAKET DOMESTIC', 'DHL Paket Domestic', '德国境内包裹'],
                    ['paket_international', 'DHL PAKET International', 'DHL Paket International', '国际包裹'],
                    ['express', 'DHL Express', 'DHL Express', '国际快件'],
                ],
            ],
            'UPS' => [
                'en' => 'UPS', 'zh' => '联合包裹', 'default' => 'ground',
                'services' => [
                    ['next_day_air', 'UPS Next Day Air', 'UPS Next Day Air', '特快'],
                    ['second_day_air', 'UPS 2nd Day Air', 'UPS 2nd Day Air', '加急'],
                    ['ground', 'UPS Ground', 'UPS Ground', '标准'],
                ],
            ],
            'FedEx' => [
                'en' => 'FedEx', 'zh' => '联邦快递', 'default' => 'ground_home_delivery',
                'services' => [
                    ['priority_overnight', 'FedEx Priority Overnight', 'FedEx Priority Overnight', '特快'],
                    ['two_day', 'FedEx 2Day', 'FedEx 2Day', '加急'],
                    ['ground_home_delivery', 'FedEx Ground / Home Delivery', 'FedEx Ground / Home Delivery', '标准'],
                ],
            ],
            'Canada Post' => [
                'en' => 'Canada Post', 'zh' => '加拿大邮政', 'default' => 'expedited_parcel',
                'services' => [
                    ['priority', 'Canada Post Priority', 'Priority', '特快'],
                    ['xpresspost', 'Canada Post Xpresspost', 'Xpresspost', '加急'],
                    ['expedited_parcel', 'Canada Post Expedited Parcel', 'Expedited Parcel', '标准'],
                    ['regular_parcel', 'Canada Post Regular Parcel', 'Regular Parcel', '经济'],
                ],
            ],
            'SF Express' => [
                'en' => 'SF Express', 'zh' => '顺丰速运', 'default' => 'standard',
                'services' => [
                    ['premium', 'SF Express Premium', 'SF Express Premium', '顺丰特快'],
                    ['standard', 'SF Standard Express', 'SF Standard Express', '顺丰标快'],
                    ['economy', 'SF Economy', 'SF Economy', '顺丰经济'],
                ],
            ],
            'Singapore Post' => [
                'en' => 'Singapore Post', 'zh' => '新加坡邮政', 'default' => 'registered_tracked',
                'services' => [
                    ['speedpost_express', 'Speedpost Express', 'Speedpost Express', '特快'],
                    ['speedpost_priority', 'Speedpost Priority', 'Speedpost Priority', '优先'],
                    ['registered_tracked', 'Registered / Tracked', 'Registered / Tracked', '挂号跟踪'],
                ],
            ],
            'POSTNL international' => [
                'en' => 'POSTNL', 'zh' => '佰事达', 'default' => 'international_tracked',
                'services' => [
                    ['international_tracked', 'POSTNL International Tracked', 'International Tracked', '国际跟踪'],
                    ['international_registered', 'POSTNL International Registered', 'International Registered', '国际挂号'],
                    ['international_standard', 'POSTNL International Standard', 'International Standard', '国际平邮'],
                ],
            ],
            'Weship' => [
                'en' => 'Weship', 'zh' => '纬狮', 'default' => 'standard',
                'services' => [
                    ['express', 'Express', 'Express', '特快'],
                    ['priority', 'Priority', 'Priority', '加急'],
                    ['standard', 'Standard', 'Standard', '普通'],
                ],
            ],
            'KingTrans' => [
                'en' => 'KingTrans', 'zh' => '唐歌', 'default' => 'standard',
                'services' => [
                    ['express', 'Express', 'Express', '特快'],
                    ['priority', 'Priority', 'Priority', '加急'],
                    ['standard', 'Standard', 'Standard', '普通'],
                ],
            ],
            'JD' => [
                'en' => 'JD', 'zh' => '京东快递', 'default' => 'standard',
                'services' => [
                    ['express', 'JD Express', 'JD Express', '京东特快'],
                    ['standard', 'JD Standard', 'JD Standard', '京东标快'],
                    ['economy', 'JD Economy', 'JD Economy', '京东经济'],
                ],
            ],
        ];

        $now = now();
        foreach ($catalog as $carrierName => $definition) {
            $carrierId = DB::table('logistics_carriers')->where('name', $carrierName)->value('id');
            if ($carrierId) {
                DB::table('logistics_carriers')->where('id', $carrierId)->update([
                    'name_en' => $definition['en'],
                    'name_zh' => $definition['zh'],
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
            } else {
                $carrierId = DB::table('logistics_carriers')->insertGetId([
                    'name' => $carrierName,
                    'name_en' => $definition['en'],
                    'name_zh' => $definition['zh'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            foreach ($definition['services'] as $index => [$key, $value, $nameEn, $nameZh]) {
                DB::table('logistics_carrier_services')->updateOrInsert(
                    ['carrier_id' => $carrierId, 'service_key' => $key],
                    [
                        'service_value' => $value,
                        'name_en' => $nameEn,
                        'name_zh' => $nameZh,
                        'is_active' => true,
                        'sort_order' => ($index + 1) * 10,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            $defaultServiceId = DB::table('logistics_carrier_services')
                ->where('carrier_id', $carrierId)
                ->where('service_key', $definition['default'])
                ->value('id');
            DB::table('logistics_carriers')->where('id', $carrierId)->update([
                'default_service_id' => $defaultServiceId,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_service_id');
        });
        Schema::table('logistics_carriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_service_id');
        });
        Schema::dropIfExists('logistics_carrier_services');
        Schema::table('logistics_carriers', function (Blueprint $table): void {
            $table->dropColumn(['name_en', 'name_zh', 'updated_by']);
        });
    }
};
