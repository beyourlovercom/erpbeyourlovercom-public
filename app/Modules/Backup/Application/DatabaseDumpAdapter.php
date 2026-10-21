<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\DatabaseDumper;
use RuntimeException;

class DatabaseDumpAdapter implements DatabaseDumper
{
    public function dump(string $destination): void
    {
        $connection = config('database.default');
        $settings = config("database.connections.{$connection}");
        if (($settings['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backup database dump requires the MySQL mysqldump adapter.');
        }

        $binary = (string) config('backup.tooling.mysqldump', 'mysqldump');
        $command = [$binary, '--single-transaction', '--quick', '--routines', '--triggers', '--host='.($settings['host'] ?? '127.0.0.1'), '--port='.($settings['port'] ?? 3306), '--user='.($settings['username'] ?? ''), (string) ($settings['database'] ?? '')];
        $this->run($command, $destination, (string) ($settings['password'] ?? ''));
        $this->gzip($destination);
    }

    /** @param list<string> $command */
    protected function run(array $command, string $destination, string $password): void
    {
        $descriptor = [1 => ['file', $destination, 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes, base_path(), ['MYSQL_PWD' => $password]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start mysqldump.');
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            @unlink($destination);
            throw new RuntimeException('mysqldump failed without exposing command credentials: '.trim((string) $error));
        }
    }

    protected function gzip(string $destination): void
    {
        $input = fopen($destination, 'rb');
        $output = gzopen($destination.'.gz', 'wb9');
        if (! is_resource($input) || $output === false) throw new RuntimeException('Unable to gzip database dump.');
        while (! feof($input)) gzwrite($output, (string) fread($input, 1024 * 1024));
        fclose($input); gzclose($output); rename($destination.'.gz', $destination);
    }
}
