<?php

namespace Database\Factories;

use App\Models\EquipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentItem>
 */
class EquipmentItemFactory extends Factory
{
    protected $model = EquipmentItem::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'category' => $this->faker->randomElement(['camera', 'lighting', 'tools']),
            'serial_number' => $this->faker->unique()->bothify('SN-####'),
            'asset_tag' => $this->faker->unique()->bothify('AT-####'),
            'condition' => 'good',
            'total_qty' => $this->faker->numberBetween(1, 10),
        ];
    }
}
