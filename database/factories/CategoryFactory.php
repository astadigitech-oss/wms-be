<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
 
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    { 
        return [];
    }

    public function cat1()
    {
        return $this->state(function (array $attributes) {
            return [
                'name_category' => 'TOYS HOBBIES (200-699)',
                'discount_category' => 50,
                'max_price_category' => 699999
            ];
        });
    }

    public function cat2()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'TOYS HOBBIES (>700)',
                'discount_category' => 40,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat3()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'FMCG',
                'discount_category' => 50,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat4()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'BABY PRODUCT',
                'discount_category' => 40,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat5()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'OTOMOTIF MOTOR',
                'discount_category' => 60,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat6()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'OTOMOTIF MOBIL',
                'discount_category' => 60,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat7()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ELEKTRONIK HV',
                'discount_category' => 30,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat8()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ELEKTRONIK ART',
                'discount_category' => 40,
                'max_price_category' => 10000000
            ];
        });
    }

    public function cat9()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ACC (0-499)',
                'discount_category' => 60,
                'max_price_category' => 499999
            ];
        });
    }
    
    public function cat10()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ACC (>500)',
                'discount_category' => 50,
                'max_price_category' => 10000000
            ];
        });
    }
    public function cat11()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'FASHION',
                'discount_category' => 60,
                'max_price_category' => 10000000
            ];
        });
    }
    public function cat12()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ATK',
                'discount_category' => 50,
                'max_price_category' => 10000000
            ];
        });
    }
    public function cat13()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ART HV',
                'discount_category' => 40,
                'max_price_category' => 10000000
            ];
        });
    }
    public function cat14()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'TOYS HOBBIES (0-199)',
                'discount_category' => 60,
                'max_price_category' => 199999
            ];
        });
    }
    public function cat15()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'ART',
                'discount_category' => 50,
                'max_price_category' => 10000000
            ];
        });
    }
    public function cat16()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'OTHER',
                'discount_category' => 50,
                'max_price_category' => 2000000
            ];
        });
    }
    public function cat17()
    {
        return $this->state(function (array $attributes){
            return [
                'name_category' => 'TOOLS',
                'discount_category' => 50,
                'max_price_category' => 2000000
            ];
        });
    }

   

}
