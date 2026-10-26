<?php

namespace Database\Factories;

use App\Models\EquipmentBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentBlock>
 */
class EquipmentBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_item_id' => null,
            'starts_on' => now()->addDays(7)->toDateString(),
            'ends_on' => now()->addDays(8)->toDateString(),
            'reason' => $this->faker->sentence(3),
        ];
    }
}
