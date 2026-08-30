<?php

declare(strict_types=1);

namespace App\Modules\Backup\Domain;

interface BackupRepository
{
    /** @return array<string, array{columns: list<string>, rows: list<array<string, mixed>>}> */
    public function exportTables(): array;

    /**
     * @param array<string, array{columns: list<string>, rows: list<array<string, mixed>>}> $tables
     * @return array<string, int>
     */
    public function restoreTables(array $tables): array;
}
