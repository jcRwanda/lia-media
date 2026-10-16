<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaRoutesTest extends TestCase
{
    public function test_spa_serves_client_routes_including_ones_starting_with_reserved_prefixes(): void
    {
        foreach (['/', '/login', '/upload', '/uploads', '/library', '/apiary', '/s/abc', '/admin/settings'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="root"', false);
        }
        $this->getJson('/api/nope')->assertNotFound();
        $this->get('/up')->assertOk();
    }
}
