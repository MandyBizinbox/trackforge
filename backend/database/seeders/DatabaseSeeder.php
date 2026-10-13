<?php

namespace Database\Seeders;

use App\Models\EquipmentCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the standard equipment categories.
     *
     * Safe to run repeatedly.
     * Does not create users, units, or equipment.
     */
    public function run(): void
    {
        $categories = [
            'Camping',
            'Tents',
            'Cooking',
            'Fire & Axes',
            'Pioneering',
            'First Aid',
            'Navigation',
            'Water Activities',
            'Games',
            'Training Equipment',
            'Meerkat Equipment',
            'Cub Equipment',
            'Scout Equipment',
            'Event Equipment',
            'Consumables',
            'Maintenance',
        ];

        foreach ($categories as $index => $name) {
            EquipmentCategory::firstOrCreate(
                [
                    'slug' => Str::slug($name),
                ],
                [
                    'name' => $name,
                    'sort_order' => $index,
                ]
            );
        }
    }
}
