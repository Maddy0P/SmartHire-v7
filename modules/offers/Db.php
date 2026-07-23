<?php
// ═════════════════════════════════════════════════════════════════════════════
//  Offers module — DB adapter (Module 10). Minimal injectable seam over the app's
//  global prepared-statement helpers so the repository is unit-testable with a
//  fake. Mirrors the interview/assessment modules; each module owns its binding.
// ═════════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

namespace SmartHire\Offer;

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
