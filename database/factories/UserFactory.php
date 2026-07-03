<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }

    public function account($index): UserFactory
    {

        $name = [
            'sugeng',
            'anas',
            'firdy',
            'freddy',
            'safrudin',
            'hayyi',
            'developer'
        ];

        $username = [
            'sugeng1',
            'anas1',
            'firdy1',
            'freddy1', 
            'safrudin1',
            'hayyi1',
            'developer'
        ];
        
        $email = [
            'sugeng@gmail.com',
            'anas@gmail.com',
            'isagagah3@gmail.com',
            'freddy@gmail.com',
            'gebus@gmail.com',
            'cok@gmail.com',
            'developer@gmail.com'
        ];
        $role_id = [1, 2, 3, 4, 5, 6,7];

        return $this->state(function (array $attributes) use ($name, $username, $email, $role_id, $index){
            return [
                'name' => $name[$index % count($name)],
                'username' => $username[$index % count($username)],
                'email' => $email[$index % count($email)],
                'password' => 'password',
                'role_id' => $role_id[$index % count($role_id)],
            ];
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
