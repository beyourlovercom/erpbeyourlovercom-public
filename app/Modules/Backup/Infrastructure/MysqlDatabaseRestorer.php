<?php

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Application\Contracts\DatabaseRestorer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MysqlDatabaseRestorer implements DatabaseRestorer
{
    public function restore(string $dump): void
    {
        $real = realpath($dump);
        if ($real === false || ! is_file($real) || is_link($dump) || ! str_ends_with($real, '.sql.gz')) {
            throw new RuntimeException('Restore database dump is unavailable.');
        }

        $connection = (string) config('database.default');
        $settings = config("database.connections.{$connection}");
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Restore database requires the MySQL adapter.');
        }

        $command = [
            (string) config('backup.tooling.mysql', 'mysql'),
            '--protocol=TCP',
            '--binary-mode',
            '--host='.($settings['host'] ?? '127.0.0.1'),
            '--port='.($settings['port'] ?? 3306),
            '--user='.($settings['username'] ?? ''),
            '--database='.(string) ($settings['database'] ?? ''),
        ];
        $this->streamGzipToMysql($command, $real, (string) ($settings['password'] ?? ''));
        DB::purge($connection);
    }

    /** @param list<string> $command */
    private function streamGzipToMysql(array $command, string $dump, string $password): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), ['MYSQL_PWD' => $password]);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start MySQL restore.');
        }

        $input = gzopen($dump, 'rb');
        if ($input === false) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Unable to read compressed database dump.');
        }

        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, 1024 * 1024);
                if ($chunk === false) throw new RuntimeException('Unable to read compressed database dump.');
                $this->writeAll($pipes[0], $chunk);
            }
            gzclose($input);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('MySQL restore failed.');
        } catch (\Throwable $e) {
            if (is_resource($input)) gzclose($input);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            throw $e;
        }
    }

    private function writeAll($stream, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Unable to stream database restore.');
            $offset += $written;
        }
    }
}
