<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $uuid = (string) Str::uuid();
        $name = Str::slug(fake()->words(2, true)).'.jpg';

        return [
            'uuid' => $uuid, 'kind' => 'image', 'title' => ucfirst(fake()->words(3, true)), 'original_filename' => $name, 'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(100_000, 9_000_000), 'sha256' => hash('sha256', $uuid), 'storage_key' => "originals/2026/10/{$uuid}/{$name}",
            'width' => 4000, 'height' => 3000, 'review_state' => 'unreviewed', 'consent_status' => 'unknown',
        ];
    }

    public function state_(string $state): static
    {
        return $this->state(fn () => ['review_state' => $state]);
    }
}
