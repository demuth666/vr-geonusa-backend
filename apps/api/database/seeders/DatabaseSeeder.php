<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(BorobudurSeeder::class);
        $this->call(AssessmentSeeder::class);
        $this->call(AdminUserSeeder::class);
    }
}
