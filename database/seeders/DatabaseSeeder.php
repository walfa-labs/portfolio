<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: the demo content attaches the tags `TagSeeder` creates, and
     * `AdministratorSeeder` owns the single profile row the demo writings read
     * their author name from (§2.7).
     */
    public function run(): void
    {
        $this->call([
            AdministratorSeeder::class,
            TagSeeder::class,
            DemoContentSeeder::class,
        ]);
    }
}
