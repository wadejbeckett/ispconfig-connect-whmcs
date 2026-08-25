<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Ispconfigconnect\Server;

/**
 * One WHMCS server row joined to its cached remote metadata.
 *
 * Source columns (live-verified on WHMCS 9.0.6): `tblservers.id/name/hostname`
 * LEFT JOINed to `tblservers_remote.meta_data/updated_at`, where meta_data
 * holds the array RemoteMetaData::gather() returned, stored verbatim as JSON.
 * A server WHMCS has never refreshed simply has no tblservers_remote row, so
 * every joined column can be null.
 *
 * Pure value object: no WHMCS calls, no I/O. hooks.php reads the rows (that is
 * where Capsule lives) and hands them here.
 */
final readonly class ServerMetaData
{
    /** @param array<string, mixed> $values decoded tblservers_remote.meta_data */
    public function __construct(
        public int $serverId,
        public string $name,
        public string $hostname,
        public array $values,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Builds one instance from a database row, tolerating every shape the
     * driver may hand back: missing columns, NULL joins, meta_data as a JSON
     * string or as an already-decoded array, and corrupt JSON. This runs on
     * every admin page load, so it must never throw.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $updatedAt = $row['updated_at'] ?? null;
        $updatedAt = is_scalar($updatedAt) ? trim((string) $updatedAt) : '';

        return new self(
            (int) (is_scalar($row['id'] ?? null) ? $row['id'] : 0),
            is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : '',
            self::decodeValues($row['meta_data'] ?? null),
            $updatedAt === '' ? null : $updatedAt,
        );
    }

    /** @return array<string, mixed> */
    private static function decodeValues(mixed $metaData): array
    {
        if (is_array($metaData)) {
            return self::stringKeyed($metaData);
        }
        if (!is_string($metaData) || trim($metaData) === '') {
            return [];
        }

        $decoded = json_decode($metaData, true);

        // A scalar or a JSON list is not the flat map GetRemoteMetaData
        // returns - treat anything else as "no data".
        return is_array($decoded) ? self::stringKeyed($decoded) : [];
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
