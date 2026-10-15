<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Usage;

/**
 * Unit conversion between ISPConfig's quota vocabulary and the one WHMCS
 * stores. Pure, static, no I/O - the conversions are the whole risk surface of
 * usage billing, so they live in one place with their own test.
 *
 * The two vocabularies:
 *
 * ISPConfig speaks a different unit per field. quota_lib.inc.php's *_raw
 * members are BYTES (it multiplies the repquota KILOBYTE block counts by
 * 1024); web_domain.hd_quota and web_domain.traffic_quota are MEGABYTES;
 * mail_user.quota is BYTES; web_database.database_quota is MEGABYTES, and
 * quota_lib expands it to database_quota_raw with a DECIMAL 1000*1000 factor.
 * "No limit" is a non-positive sentinel everywhere, but which non-positive
 * value differs: -1 for hd_quota/traffic_quota/database_quota and for the
 * soft_raw/hard_raw members quota_lib rewrites, 0 for mail_user.quota.
 *
 * WHMCS speaks megabytes only: tblhosting.diskusage/disklimit/bwusage/bwlimit
 * are documented as "in megabytes" by the UpdateClientProduct API reference
 * (developers.whmcs.com/api-reference/updateclientproduct/), which writes the
 * same columns UsageUpdate does.
 *
 * The megabyte is BINARY (2^20). ISPConfig treats its own MB that way:
 * 300-quota_notify.inc.php compares traffic_quota against
 * traffic_bytes/1024/1024, and apache2_plugin.inc.php turns hd_quota into
 * hd_quota*1024 one-kilobyte quota blocks.
 */
final class QuotaMath
{
    public const BYTES_PER_MEGABYTE = 1048576;

    /**
     * Coerce any value ISPConfig can put on the wire into an int.
     *
     * mysqli hands back every column as a string, quota_lib leaves NULL where
     * a monitor entry is missing, and its non-numeric fallbacks can leave an
     * array in place. Anything that is not a numeric scalar is 0.
     */
    public static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * True for every "no limit" sentinel: a limit is only real when it is a
     * positive number, so -1, 0, null, '' and any non-numeric value all mean
     * unlimited. That single rule covers hd_quota/traffic_quota (-1 default,
     * 0 also unlimited per apache2_plugin.inc.php), quota_lib's soft_raw and
     * hard_raw (0 rewritten to -1), database_quota (-1) and mail_user.quota
     * (0).
     */
    public static function isUnlimited(mixed $value): bool
    {
        return self::toInt($value) <= 0;
    }

    /** Bytes -> whole megabytes, rounded to nearest; never negative. */
    public static function bytesToMegabytes(mixed $bytes): int
    {
        $value = self::toInt($bytes);
        if ($value <= 0) {
            return 0;
        }

        return (int) round($value / self::BYTES_PER_MEGABYTE);
    }

    /** Megabytes -> bytes; a non-positive (unlimited) value becomes 0. */
    public static function megabytesToBytes(mixed $megabytes): int
    {
        $value = self::toInt($megabytes);
        if ($value <= 0) {
            return 0;
        }

        return $value * self::BYTES_PER_MEGABYTE;
    }
}
