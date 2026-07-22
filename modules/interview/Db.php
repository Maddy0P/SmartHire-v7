<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Interview module — DB adapter (Module 9). A minimal injectable seam over the
//  app's global db helpers so the repository can be unit-tested with a fake.
//  Mirrors the assessment module's DbAdapter shape; kept local to keep the two
//  modules decoupled (each owns its own infrastructure binding).
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Interview;

interface DbAdapter
{
    public function fetchAll(string $sql, string $types = '', mixed ...$params): array;
    public function fetchOne(string $sql, string $types = '', mixed ...$params): ?array;
    public function execute(string $sql, string $types = '', mixed ...$params): bool|int;
}

/** Production adapter — delegates to the app's global prepared-statement helpers. */
final class GlobalDb implements DbAdapter
{
    public function fetchAll(string $sql, string $types = '', mixed ...$params): array
    {
        return dbFetchAll($sql, $types, ...$params);
    }
    public function fetchOne(string $sql, string $types = '', mixed ...$params): ?array
    {
        return dbFetchOne($sql, $types, ...$params);
    }
    public function execute(string $sql, string $types = '', mixed ...$params): bool|int
    {
        return dbExecute($sql, $types, ...$params);
    }
}
