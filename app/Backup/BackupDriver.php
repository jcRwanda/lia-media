<?php

namespace App\Backup;

/**
 * Pluggable backup target (e.g. rclone to a second cloud + local disk). Not enabled yet:
 * the archive ships with NullBackupDriver; a real driver only needs to implement this.
 */
interface BackupDriver
{
    public function name(): string;

    public function enabled(): bool;

    /** Copy one stored object to the backup target. */
    public function backupObject(string $key, string $sha256): BackupResult;

    /** Check that a previously backed-up object is present and intact. */
    public function verifyObject(string $key, string $sha256): BackupResult;
}
