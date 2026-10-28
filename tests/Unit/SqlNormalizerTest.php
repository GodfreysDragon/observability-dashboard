<?php

namespace Tests\Unit;

use App\Observability\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function statements(): array
    {
        return [
            'string literals, including escaped quotes' => [
                "select * from users where name = 'O''Brien' and note = 'it\\'s'",
                'select * from users where name = ? and note = ?',
            ],
            'numbers but not digits inside identifiers' => [
                'select col2 from t1 where "t1"."col3" > 10 and price = -4.50 limit 25',
                'select col2 from t1 where "t1"."col3" > ? and price = ? limit ?',
            ],
            'in-lists of any length' => [
                'select * from invoices where id in (?, ?, ?)',
                'select * from invoices where id in (...)',
            ],
            'multi-row inserts' => [
                'insert into lines (a, b) values (?, ?), (?, ?), (?, ?)',
                'insert into lines (a, b) values (?, ?), ...',
            ],
            'whitespace' => [
                "select *\n\tfrom   invoices\n where id = ?",
                'select * from invoices where id = ?',
            ],
        ];
    }

    #[DataProvider('statements')]
    public function test_it_normalizes(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlNormalizer::normalize($sql));
    }

    public function test_eager_loads_of_different_sizes_share_a_fingerprint(): void
    {
        $two = SqlNormalizer::normalize('select * from customers where id in (?, ?)');
        $five = SqlNormalizer::normalize('select * from customers where id in (?, ?, ?, ?, ?)');

        $this->assertSame(SqlNormalizer::fingerprint($two), SqlNormalizer::fingerprint($five));
        $this->assertSame(16, strlen(SqlNormalizer::fingerprint($two)));
    }

    public function test_it_recognises_selects(): void
    {
        $this->assertTrue(SqlNormalizer::isSelect('  SELECT 1'));
        $this->assertFalse(SqlNormalizer::isSelect('update invoices set status = ?'));
        $this->assertFalse(SqlNormalizer::isSelect('selection_log'));
    }
}
