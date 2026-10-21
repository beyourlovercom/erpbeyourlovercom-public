<?php

namespace Tests\Feature\Sync;

use App\Support\Sync\SyncOutboxService;
use App\Support\Sync\SyncOutboxStatus;
use App\Support\Sync\SyncOutboxWriteException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class MySqlTwoConnectionConcurrencyTest extends TestCase
{
    private string $socket;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->socket = (string) getenv('BYL_MYSQL_CONCURRENCY_SOCKET');
        $this->database = (string) getenv('BYL_MYSQL_CONCURRENCY_DATABASE');

        if (getenv('BYL_MYSQL_CONCURRENCY_ENABLED') !== '1') {
            $this->markTestSkipped('MySQL two-connection validation is disabled.');
        }

        $this->assertIsolatedEnvironment();
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', 'localhost');
        Config::set('database.connections.mysql.port', 0);
        Config::set('database.connections.mysql.unix_socket', $this->socket);
        Config::set('database.connections.mysql.database', $this->database);
        Config::set('database.connections.mysql.username', 'root');
        Config::set('database.connections.mysql.password', '');
        DB::purge('mysql');

        Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (getenv('BYL_MYSQL_CONCURRENCY_ENABLED') === '1') {
            DB::purge('mysql');
        }

        parent::tearDown();
    }

    public function test_two_independent_mysql_connections_allow_exactly_one_concurrent_claim(): void
    {
        $connection = DB::connection('mysql');
        $this->assertSame('8.4.11', $connection->scalar('select version()'));
        $this->assertSame('Homebrew', $connection->scalar('select @@version_comment'));
        $this->assertSame('InnoDB', $connection->table('information_schema.tables')
            ->where('table_schema', $this->database)
            ->where('table_name', 'sync_outbox')
            ->value('engine'));

        $record = app(SyncOutboxService::class)->enqueue([
            'idempotency_key' => 'mysql-two-connection-claim',
            'operation' => 'architecture.mysql.concurrency.validate',
            'max_attempts' => 3,
        ]);

        $runRoot = dirname($this->socket);
        $goFile = $runRoot.'/claim-go';
        $resultFiles = [$runRoot.'/claim-one.json', $runRoot.'/claim-two.json'];
        $processes = [];

        foreach ($resultFiles as $resultFile) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork a MySQL claimant.');
            }
            if ($pid === 0) {
                $this->runClaimant($record->id, $goFile, $resultFile);
            }
            $processes[] = $pid;
        }

        touch($goFile);
        foreach ($processes as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $results = array_map(fn (string $path): array => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), $resultFiles);
        $connectionIds = array_column($results, 'connection_id');
        $this->assertCount(2, array_unique($connectionIds));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'claimed'));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'rejected'));

        $updated = DB::connection('mysql')->table('sync_outbox')->where('id', $record->id)->first();
        $this->assertSame(SyncOutboxStatus::Processing->value, $updated->status);
        $this->assertSame(1, $updated->attempts);
        $this->assertNotEmpty($updated->lock_token);
        $this->assertNotNull($updated->locked_at);
    }

    private function runClaimant(int $recordId, string $goFile, string $resultFile): never
    {
        while (! file_exists($goFile)) {
            usleep(1_000);
        }

        DB::purge('mysql');
        $connectionId = (int) DB::connection('mysql')->scalar('select connection_id()');
        try {
            app(SyncOutboxService::class)->claim($recordId);
            file_put_contents($resultFile, json_encode(['connection_id' => $connectionId, 'result' => 'claimed'], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (SyncOutboxWriteException) {
            file_put_contents($resultFile, json_encode(['connection_id' => $connectionId, 'result' => 'rejected'], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable) {
            exit(1);
        }
    }

    private function assertIsolatedEnvironment(): void
    {
        $repository = realpath(dirname(__DIR__, 3));
        $socketDirectory = realpath(dirname($this->socket));
        $this->assertNotFalse($repository);
        $this->assertNotFalse($socketDirectory);
        $this->assertFileExists($this->socket);
        $this->assertStringStartsWith('/private/tmp/', $socketDirectory);
        $this->assertStringNotContainsString($repository, $socketDirectory);
        $this->assertMatchesRegularExpression('/^byl_001fc_[a-z0-9_]+$/', $this->database);
    }
}
