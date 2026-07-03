<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\Buyer;
use App\Models\User;
use App\Models\Category;
use App\Models\Color_tag;
use App\Models\ColorTag2;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        Buyer::factory(10)->create();

        Category::factory()->cat1()->create();
        Category::factory()->cat2()->create();
        Category::factory()->cat3()->create();
        Category::factory()->cat4()->create();
        Category::factory()->cat5()->create();
        Category::factory()->cat6()->create();
        Category::factory()->cat7()->create();
        Category::factory()->cat8()->create();
        Category::factory()->cat9()->create();
        Category::factory()->cat10()->create();
        Category::factory()->cat11()->create();
        Category::factory()->cat12()->create();
        Category::factory()->cat13()->create();
        Category::factory()->cat14()->create();
        Category::factory()->cat15()->create();
        Category::factory()->cat16()->create();
        Category::factory()->cat17()->create();

        Color_tag::factory()->merah()->create();
        Color_tag::factory()->biru()->create();

        ColorTag2::factory()->merah()->create();
        ColorTag2::factory()->biru()->create();


        $users = Role::factory();
        foreach (range(0, 6) as $index) {
            $users->role($index)->create();
        };

        $users = User::factory();
        foreach (range(0, 6) as $index) {
            $users->account($index)->create();
        };
    }
}
