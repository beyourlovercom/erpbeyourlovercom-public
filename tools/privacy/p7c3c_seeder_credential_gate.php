<?php
declare(strict_types=1);

namespace Illuminate\Database {
    class Seeder {}
}

namespace {
    $seeder = $argv[1] ?? '';
    if (!is_file($seeder)) {
        throw new \RuntimeException('Missing P82UatSeeder source');
    }
    require $seeder;
    $method = new \ReflectionMethod(\Database\Seeders\P82UatSeeder::class, 'credentials');

    $run = static function (bool $expectSuccess) use ($method): void {
        try {
            $result = $method->invoke(null);
            if (!$expectSuccess || !is_array($result) || count($result) !== 2) {
                throw new \RuntimeException('Unexpected credentials acceptance');
            }
        } catch (\RuntimeException $e) {
            if ($expectSuccess || $e->getMessage() === 'Unexpected credentials acceptance') {
                throw $e;
            }
        }
    };

    putenv('BYL_P82_UAT_EMAIL');
    putenv('BYL_P82_UAT_PASSWORD');
    $run(false);
    putenv('BYL_P82_UAT_EMAIL=uat.operator@example.invalid');
    $run(false);
    putenv('BYL_P82_UAT_PASSWORD=short');
    $run(false);
    putenv('BYL_P82_UAT_EMAIL=real@example.com');
    putenv('BYL_P82_UAT_PASSWORD='.bin2hex(random_bytes(16)));
    $run(false);
    putenv('BYL_P82_UAT_EMAIL=uat.operator@example.invalid');
    $run(true);
    putenv('BYL_P82_UAT_EMAIL');
    putenv('BYL_P82_UAT_PASSWORD');
    echo "P7C3C_CREDENTIAL_ENV_GATE_PASS\n";
}
