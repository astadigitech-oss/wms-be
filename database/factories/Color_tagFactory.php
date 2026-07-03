<?php

namespace Database\Factories;

use App\Models\Color_tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Color_tag>
 */
class Color_tagFactory extends Factory
{
    protected $model = Color_tag::class;
   
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
                'max_price_color' => 49999,
                'fixed_price_color' => 25000
            ];
        });
    }
    public function biru()
    {
        return $this->state(function (array $attributes) {
            return [
                'hexa_code_color' => '#0000FF',
                'name_color' => 'biru',
                'min_price_color' => 49999,
                'max_price_color' => 99999,
                'fixed_price_color' => 50000
            ];
        });
    }

}
