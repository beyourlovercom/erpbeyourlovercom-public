<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class MySqlProductionCompatibilityTest extends TestCase
{
    private const FORBIDDEN_SQL_PATTERNS = [
        'cast-as-numeric' => '/\bCAST\s*\([^;]*?\s+AS\s+NUMERIC\s*\)/is',
        'postgres-type-cast' => '/::\s*(?:numeric|decimal|text|jsonb|date|timestamp)\b/i',
        'ilike' => '/\bILIKE\b/i',
        'nulls-ordering' => '/\bNULLS\s+(?:FIRST|LAST)\b/i',
        'aggregate-filter' => '/\bFILTER\s*\(\s*WHERE\b/i',
        'on-conflict' => '/\bON\s+CONFLICT\b/i',
    ];

    public function test_production_php_sources_do_not_contain_known_mysql_incompatible_sql(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach ([$root.'/app', $root.'/database/migrations'] as $directory) {
            foreach ($this->phpFiles($directory) as $file) {
                $contents = (string) file_get_contents($file);
                foreach (self::FORBIDDEN_SQL_PATTERNS as $name => $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = $name.':'.str_replace($root.'/', '', $file);
                    }
                }
            }
        }

        self::assertSame([], $violations, "Known MySQL-incompatible SQL detected:\n".implode("\n", $violations));
    }

    #[DataProvider('forbiddenSqlProvider')]
    public function test_guard_detects_representative_forbidden_sql(string $sql, string $patternName): void
    {
        self::assertSame(1, preg_match(self::FORBIDDEN_SQL_PATTERNS[$patternName], $sql));
    }

    public static function forbiddenSqlProvider(): array
    {
        return [
            ['CAST(total AS NUMERIC)', 'cast-as-numeric'],
            ['amount::numeric', 'postgres-type-cast'],
            ['name ILIKE ?', 'ilike'],
            ['ORDER BY created_at NULLS LAST', 'nulls-ordering'],
            ['COUNT(*) FILTER (WHERE active = 1)', 'aggregate-filter'],
            ['INSERT INTO x VALUES (1) ON CONFLICT DO NOTHING', 'on-conflict'],
        ];
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
