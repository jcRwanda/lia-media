<?php

namespace App\Backup;

final class BackupResult
{
    public function __construct(public readonly bool $ok, public readonly string $message = '') {}
}
