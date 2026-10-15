<?php

declare(strict_types=1);

namespace Ispconfigconnect\Tests\Unit\Usage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Ispconfigconnect\Usage\QuotaMath;

/**
 * The unit conversions are the whole game for usage billing, so they live in
 * a pure class and are pinned here against the shapes ISPConfig 3.3.1p1
 * actually puts on the wire: mysqli's stringly typed columns, unserialized
 * monitor values, PHP nulls where monitor data is missing, and the -1/0
 * unlimited sentinels.
 */
#[CoversClass(QuotaMath::class)]
final class QuotaMathTest extends TestCase
{
    /** @return iterable<string, array{mixed, int}> */
    public static function numericValues(): iterable
    {
        yield 'int' => [1234, 1234];
        yield 'mysqli string' => ['1234', 1234];
        yield 'float' => [1234.7, 1234];
        yield 'numeric string with spaces' => ['  1234  ', 1234];
        yield 'null (monitor entry missing)' => [null, 0];
        yield 'empty string' => ['', 0];
        yield 'non-numeric string' => ['n/a', 0];
        yield 'bool true' => [true, 0];
        yield 'array (unmerged monitor value)' => [[0, 5], 0];
        yield 'negative sentinel' => ['-1', -1];
        yield 'big byte count' => ['1099511627776', 1099511627776];
    }

    #[DataProvider('numericValues')]
    public function testToIntCoercesEveryShapeTheApiCanReturn(mixed $value, int $expected): void
    {
        self::assertSame($expected, QuotaMath::toInt($value));
    }

    public function testBytesPerMegabyteIsBinary(): void
    {
        // ISPConfig itself divides traffic_bytes by 1024/1024 before comparing
        // it with traffic_quota (server/lib/classes/cron.d/300-quota_notify.inc.php),
        // and multiplies hd_quota by 1024 to get 1 KiB quota blocks
        // (server/plugins-available/apache2_plugin.inc.php) - so its "MB" is MiB.
        self::assertSame(1048576, QuotaMath::BYTES_PER_MEGABYTE);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function byteValues(): iterable
    {
        yield 'exact megabyte' => [1048576, 1];
        yield 'half gigabyte' => [536870912, 512];
        yield 'mysqli string sum' => ['2147483648', 2048];
        yield 'rounds to nearest' => [1572864, 2];       // 1.5 MiB
        yield 'rounds down' => [1310720, 1];             // 1.25 MiB
        yield 'sub-megabyte' => [1024, 0];
        yield 'zero' => [0, 0];
        yield 'null' => [null, 0];
        yield 'negative clamps to zero' => [-1, 0];
    }

    #[DataProvider('byteValues')]
    public function testBytesToMegabytes(mixed $bytes, int $expected): void
    {
        self::assertSame($expected, QuotaMath::bytesToMegabytes($bytes));
    }

    public function testMegabytesToBytes(): void
    {
        self::assertSame(1073741824, QuotaMath::megabytesToBytes('1024'));
        self::assertSame(0, QuotaMath::megabytesToBytes(null));
        self::assertSame(0, QuotaMath::megabytesToBytes('-1'));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function limitValues(): iterable
    {
        // Every ISPConfig limit field uses a non-positive sentinel for
        // "no limit": web_domain.hd_quota/traffic_quota default to -1,
        // quota_lib maps a zero soft/hard quota to -1 in soft_raw/hard_raw,
        // web_database.database_quota uses -1, and mail_user.quota uses 0.
        yield 'minus one' => ['-1', true];
        yield 'zero' => ['0', true];
        yield 'zero int' => [0, true];
        yield 'null' => [null, true];
        yield 'empty string' => ['', true];
        yield 'non-numeric' => ['unlimited', true];
        yield 'positive' => ['1024', false];
        yield 'positive int' => [1, false];
    }

    #[DataProvider('limitValues')]
    public function testIsUnlimited(mixed $value, bool $expected): void
    {
        self::assertSame($expected, QuotaMath::isUnlimited($value));
    }
}
