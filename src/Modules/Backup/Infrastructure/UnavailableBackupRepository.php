<?php

declare(strict_types=1);

namespace App\Modules\Backup\Infrastructure;

use App\Modules\Backup\Domain\BackupRepository;
use RuntimeException;

final class UnavailableBackupRepository implements BackupRepository
{
    public function exportTables(): array
    {
        throw new RuntimeException('Backup storage is unavailable.');
    }

    public function restoreTables(array $tables): array
    {
        throw new RuntimeException('Backup storage is unavailable.');
    }
}
