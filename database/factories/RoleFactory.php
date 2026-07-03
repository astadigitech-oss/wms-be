<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
           
        ];
    }

    public function role($index) : RoleFactory{
        $roles = [
            'Admin',
            'Spv',
            'Team leader',
            'Crew',
            'Admin Kasir',
            'Reparasi',
            'Developer'
        ];

        return $this->state(function (array $attributes) use ($roles, $index){
            return [
                'role_name' => $roles[$index % count($roles)]
            ];
        });
    }


}
