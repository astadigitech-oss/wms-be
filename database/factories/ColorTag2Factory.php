<?php

namespace Database\Factories;

use App\Models\ColorTag2;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Color_tag>
 */
class ColorTag2Factory extends Factory
{
    protected $model = ColorTag2::class;
   
    public function definition(): array
    {
        return [
            
        ];
    }

    public function merah()
    {
        return $this->state(function (array $attributes) {
            return [
                'hexa_code_color' => '#FF0000',
                'name_color' => 'merah',
                'min_price_color' => 0,
                'max_price_color' => 59999,
                'fixed_price_color' => 30000
            ];
        });
    }
    public function biru()
    {
        return $this->state(function (array $attributes) {
            return [
                'hexa_code_color' => '#0000FF',
                'name_color' => 'biru',
                'min_price_color' => 60000,
                'max_price_color' => 120000,
                'fixed_price_color' => 60000
            ];
        });
    }

}
