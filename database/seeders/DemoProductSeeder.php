<?php

namespace Database\Seeders;

use App\Enums\Condition;
use App\Enums\Rarity;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            [
                'name' => 'Algeria 2019 Home Shirt',
                'team' => 'Algeria',
                'year' => 2019,
                'condition' => Condition::MintWithTags,
                'rarity' => Rarity::Iconic,
                'description' => '2019 Africa Cup of Nations home shirt from the winning campaign in Cairo. Never worn, tags attached.',
                'price' => 6500,
                'old_price' => 8500,
                'stock' => 6,
                'is_active' => true,
            ],
            [
                'name' => 'Cameroon 2002 Home Shirt',
                'team' => 'Cameroon',
                'year' => 2002,
                'condition' => Condition::Excellent,
                'rarity' => Rarity::VeryRare,
                'description' => 'The sleeveless design worn at the 2002 World Cup. Light signs of wear, no flaws.',
                'price' => 12000,
                'old_price' => 15000,
                'stock' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Nigeria 2018 Home Shirt',
                'team' => 'Nigeria',
                'year' => 2018,
                'condition' => Condition::VeryGood,
                'rarity' => Rarity::Limited,
                'description' => 'The much sought after Naija green kit from the 2018 World Cup. Gently used.',
                'price' => 9000,
                'old_price' => 11000,
                'stock' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'Morocco 2022 Home Shirt',
                'team' => 'Morocco',
                'year' => 2022,
                'condition' => Condition::MintWithTags,
                'rarity' => Rarity::Rare,
                'description' => 'Semi final run in Qatar. Brand new with original tags.',
                'price' => 7500,
                'old_price' => 9000,
                'stock' => 8,
                'is_active' => true,
            ],
            [
                'name' => 'France 1998 Home Shirt',
                'team' => 'France',
                'year' => 1998,
                'condition' => Condition::Excellent,
                'rarity' => Rarity::Iconic,
                'description' => 'World Cup winning home shirt from 1998. Collector piece in excellent condition.',
                'price' => 18000,
                'old_price' => 22000,
                'stock' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Brazil 2002 Away Shirt',
                'team' => 'Brazil',
                'year' => 2002,
                'condition' => Condition::VeryGood,
                'rarity' => Rarity::Rare,
                'description' => 'Away shirt worn during the 2002 title run. No discount on this one.',
                'price' => 11000,
                'old_price' => null,
                'stock' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Egypt 2006 Home Shirt',
                'team' => 'Egypt',
                'year' => 2006,
                'condition' => Condition::VeryGood,
                'rarity' => Rarity::VeryRare,
                'description' => 'Africa Cup of Nations winning shirt. Sold out for now.',
                'price' => 14000,
                'old_price' => 16500,
                'stock' => 0,
                'is_active' => true,
            ],
            [
                'name' => 'Senegal 2021 Away Shirt',
                'team' => 'Senegal',
                'year' => 2021,
                'condition' => Condition::Excellent,
                'rarity' => Rarity::Limited,
                'description' => 'Africa Cup of Nations winning away shirt. Worn a couple of times.',
                'price' => 8000,
                'old_price' => 9500,
                'stock' => 5,
                'is_active' => true,
            ],
            [
                'name' => 'Tunisia 2018 Home Shirt',
                'team' => 'Tunisia',
                'year' => 2018,
                'condition' => Condition::VeryGood,
                'rarity' => Rarity::Rare,
                'description' => 'World Cup home shirt, standard fit. No discount on this one.',
                'price' => 5500,
                'old_price' => null,
                'stock' => 12,
                'is_active' => true,
            ],
            [
                'name' => 'Ivory Coast 2015 Home Shirt',
                'team' => 'Ivory Coast',
                'year' => 2015,
                'condition' => Condition::Excellent,
                'rarity' => Rarity::Limited,
                'description' => 'Home shirt from the 2015 title winning side. Hidden from the storefront for now.',
                'price' => 7000,
                'old_price' => 8000,
                'stock' => 0,
                'is_active' => false,
            ],
        ];

        foreach ($products as $attributes) {
            Product::firstOrCreate(
                ['slug' => Str::slug($attributes['name'])],
                $attributes,
            );
        }
    }
}
