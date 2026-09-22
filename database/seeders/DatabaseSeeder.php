<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            MenuSeeder::class,
            TableSeeder::class,
            InventorySeeder::class,
            DemoSalesSeeder::class,
            LiveDemoSeeder::class,
        ]);
    }
}
