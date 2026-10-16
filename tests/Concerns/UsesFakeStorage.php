<?php

namespace Tests\Concerns;

use App\Storage\FakeObjectStorage;
use App\Storage\ObjectStorage;

trait UsesFakeStorage
{
    protected FakeObjectStorage $r2;

    protected function setUpFakeStorage(): void
    {
        $this->r2 = new FakeObjectStorage;
        $this->app->instance(ObjectStorage::class, $this->r2);
    }

    protected function keyOf(string $uploadUuid): string
    {
        return \App\Models\UploadSession::query()->where('uuid', $uploadUuid)->value('storage_key');
    }
}
