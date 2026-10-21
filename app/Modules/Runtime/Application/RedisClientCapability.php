<?php

namespace App\Modules\Runtime\Application;

class RedisClientCapability
{
    public function code(string $client): string
    {
        if (! in_array($client, ['phpredis', 'predis'], true)) {
            return 'unsupported';
        }

        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            return 'extension_unavailable';
        }

        if ($client === 'predis' && ! class_exists('Predis\\Client')) {
            return 'client_unavailable';
        }

        return 'supported';
    }
}
