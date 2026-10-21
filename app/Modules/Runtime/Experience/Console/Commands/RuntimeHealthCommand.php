<?php

namespace App\Modules\Runtime\Experience\Console\Commands;

use App\Modules\Runtime\Application\RuntimeHealthService;
use Illuminate\Console\Command;

class RuntimeHealthCommand extends Command
{
    protected $signature = 'runtime:health {--json : Emit the redacted health result as JSON}';

    protected $description = 'Check the internal runtime foundation';

    public function handle(RuntimeHealthService $health): int
    {
        $result = $health->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Runtime health: '.strtoupper($result['status']));
            foreach ($result['checks'] as $name => $check) {
                $this->line(sprintf('%s: %s (%s)', $name, strtoupper($check['status']), $check['code']));
            }
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
