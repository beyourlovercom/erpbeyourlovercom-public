<?php

namespace App\Providers;

use App\Http\RequestPerformance;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestPerformance::class);
    }

    public function boot(): void
    {
        $this->loadWordPressConnectorSettings();
    }

    private function loadWordPressConnectorSettings(): void
    {
        try {
            if (! Schema::hasTable('wordpress_connector_settings')) {
                return;
            }

            $settings = DB::table('wordpress_connector_settings')->orderBy('id')->first();
            if (! $settings) {
                return;
            }
            $config = [
                'services.andy_core.url' => trim((string) ($settings->site_url ?? '')),
                'services.andy_core.connection_key' => trim((string) ($settings->connection_key ?? '')) ?: 'beyourlover.com',
                'services.andy_core.key_id' => trim((string) ($settings->key_id ?? '')),
                'services.andy_core.verify_tls' => (bool) ($settings->verify_tls ?? true),
            ];

            if (is_string($settings->shared_secret ?? null) && $settings->shared_secret !== '') {
                try {
                    $config['services.andy_core.shared_secret'] = Crypt::decryptString($settings->shared_secret);
                } catch (Throwable) {
                    $config['services.andy_core.shared_secret'] = '';
                }
            }

            config($config);
        } catch (Throwable) {
            // Database-backed connector settings are optional during bootstrap/migrations.
        }
    }
}
