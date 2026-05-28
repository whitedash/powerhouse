<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'slug' => 'maavelus',
                'name' => 'Maavelus',
                'description' => 'Marketing & growth platform for hospitality venues.',
                'icon_colour' => '#F59E0B',
                'is_active' => true,
                'is_coming_soon' => false,
                'sort_order' => 1,
            ],
            [
                'slug' => 'myorderpad',
                'name' => 'MyOrderPad',
                'description' => 'Online ordering & POS for restaurants and bars.',
                'icon_colour' => '#0D9488',
                'is_active' => true,
                'is_coming_soon' => false,
                'sort_order' => 2,
            ],
            [
                'slug' => 'whitedash',
                'name' => 'Whitedash',
                'description' => 'Whitedash main product / managed services.',
                'icon_colour' => '#0F172A',
                'is_active' => true,
                'is_coming_soon' => false,
                'sort_order' => 3,
            ],
            [
                'slug' => 'smscube',
                'name' => 'SMSCube',
                'description' => 'Outbound SMS & marketing campaigns.',
                'icon_colour' => '#7C3AED',
                'is_active' => true,
                'is_coming_soon' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
