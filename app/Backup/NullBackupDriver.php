<?php

namespace App\Backup;

class NullBackupDriver implements BackupDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function backupObject(string $key, string $sha256): BackupResult
    {
        return new BackupResult(false, 'Backups are not configured.');
    }

    public function verifyObject(string $key, string $sha256): BackupResult
    {
        return new BackupResult(false, 'Backups are not configured.');
    }
}
