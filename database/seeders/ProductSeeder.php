<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Product::create([
            'name' => 'Flash Sale Limited Edition Gadget',
            'description' => 'A highly sought-after limited edition gadget available only during the flash sale. Get it before it\'s gone!',
            'price' => 99.99,
            'stock' => 100,
            'version' => 1,
        ]);
    }
}
