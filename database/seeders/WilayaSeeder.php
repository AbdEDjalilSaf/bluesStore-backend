<?php

namespace Database\Seeders;

use App\Models\Wilaya;
use Illuminate\Database\Seeder;

class WilayaSeeder extends Seeder
{
    /**
     * Seed the shipping wilayas with their default fees.
     *
     * Shipping fees are zone based: 400 DZD for Algiers, 500 for the
     * coastal North, 600 for the High Plateaus, 700 for the Sahara and
     * 800 for the most remote southern wilayas.
     *
     * @return array<int, array{name: string, phone_number: string, shipping_fee: int}>
     */
    private function data(): array
    {
        return [
            ['name' => 'Adrar', 'phone_number' => '049', 'shipping_fee' => 800],
            ['name' => 'Chlef', 'phone_number' => '027', 'shipping_fee' => 500],
            ['name' => 'Laghouat', 'phone_number' => '029', 'shipping_fee' => 600],
            ['name' => 'Oum El Bouaghi', 'phone_number' => '032', 'shipping_fee' => 600],
            ['name' => 'Batna', 'phone_number' => '033', 'shipping_fee' => 600],
            ['name' => 'Béjaïa', 'phone_number' => '034', 'shipping_fee' => 500],
            ['name' => 'Biskra', 'phone_number' => '033', 'shipping_fee' => 700],
            ['name' => 'Béchar', 'phone_number' => '049', 'shipping_fee' => 700],
            ['name' => 'Blida', 'phone_number' => '025', 'shipping_fee' => 500],
            ['name' => 'Bouira', 'phone_number' => '026', 'shipping_fee' => 500],
            ['name' => 'Tamanrasset', 'phone_number' => '029', 'shipping_fee' => 800],
            ['name' => 'Tébessa', 'phone_number' => '037', 'shipping_fee' => 600],
            ['name' => 'Tlemcen', 'phone_number' => '043', 'shipping_fee' => 500],
            ['name' => 'Tiaret', 'phone_number' => '046', 'shipping_fee' => 600],
            ['name' => 'Tizi Ouzou', 'phone_number' => '026', 'shipping_fee' => 500],
            ['name' => 'Algiers', 'phone_number' => '021', 'shipping_fee' => 400],
            ['name' => 'Djelfa', 'phone_number' => '027', 'shipping_fee' => 600],
            ['name' => 'Jijel', 'phone_number' => '034', 'shipping_fee' => 500],
            ['name' => 'Sétif', 'phone_number' => '036', 'shipping_fee' => 600],
            ['name' => 'Saïda', 'phone_number' => '048', 'shipping_fee' => 600],
            ['name' => 'Skikda', 'phone_number' => '038', 'shipping_fee' => 500],
            ['name' => 'Sidi Bel Abbès', 'phone_number' => '048', 'shipping_fee' => 500],
            ['name' => 'Annaba', 'phone_number' => '038', 'shipping_fee' => 500],
            ['name' => 'Guelma', 'phone_number' => '037', 'shipping_fee' => 500],
            ['name' => 'Constantine', 'phone_number' => '031', 'shipping_fee' => 500],
            ['name' => 'Médéa', 'phone_number' => '025', 'shipping_fee' => 500],
            ['name' => 'Mostaganem', 'phone_number' => '045', 'shipping_fee' => 500],
            ['name' => "M'Sila", 'phone_number' => '035', 'shipping_fee' => 600],
            ['name' => 'Mascara', 'phone_number' => '045', 'shipping_fee' => 500],
            ['name' => 'Ouargla', 'phone_number' => '029', 'shipping_fee' => 700],
            ['name' => 'Oran', 'phone_number' => '041', 'shipping_fee' => 500],
            ['name' => 'El Bayadh', 'phone_number' => '049', 'shipping_fee' => 600],
            ['name' => 'Illizi', 'phone_number' => '029', 'shipping_fee' => 800],
            ['name' => 'Bordj Bou Arréridj', 'phone_number' => '035', 'shipping_fee' => 600],
            ['name' => 'Boumerdès', 'phone_number' => '024', 'shipping_fee' => 500],
            ['name' => 'El Tarf', 'phone_number' => '038', 'shipping_fee' => 500],
            ['name' => 'Tindouf', 'phone_number' => '049', 'shipping_fee' => 800],
            ['name' => 'Tissemsilt', 'phone_number' => '046', 'shipping_fee' => 600],
            ['name' => 'El Oued', 'phone_number' => '032', 'shipping_fee' => 700],
            ['name' => 'Khenchela', 'phone_number' => '032', 'shipping_fee' => 600],
            ['name' => 'Souk Ahras', 'phone_number' => '037', 'shipping_fee' => 500],
            ['name' => 'Tipaza', 'phone_number' => '024', 'shipping_fee' => 500],
            ['name' => 'Mila', 'phone_number' => '031', 'shipping_fee' => 500],
            ['name' => 'Aïn Defla', 'phone_number' => '027', 'shipping_fee' => 500],
            ['name' => 'Naâma', 'phone_number' => '049', 'shipping_fee' => 600],
            ['name' => 'Aïn Témouchent', 'phone_number' => '043', 'shipping_fee' => 500],
            ['name' => 'Ghardaïa', 'phone_number' => '029', 'shipping_fee' => 700],
            ['name' => 'Relizane', 'phone_number' => '046', 'shipping_fee' => 500],
            ['name' => 'Timimoun', 'phone_number' => '049', 'shipping_fee' => 700],
            ['name' => 'Bordj Badji Mokhtar', 'phone_number' => '049', 'shipping_fee' => 800],
            ['name' => 'Ouled Djellal', 'phone_number' => '033', 'shipping_fee' => 700],
            ['name' => 'Béni Abbès', 'phone_number' => '049', 'shipping_fee' => 700],
            ['name' => 'In Salah', 'phone_number' => '029', 'shipping_fee' => 700],
            ['name' => 'In Guezzam', 'phone_number' => '029', 'shipping_fee' => 800],
            ['name' => 'Touggourt', 'phone_number' => '029', 'shipping_fee' => 700],
            ['name' => 'Djanet', 'phone_number' => '029', 'shipping_fee' => 800],
            ['name' => "El M'Ghair", 'phone_number' => '032', 'shipping_fee' => 700],
            ['name' => 'El Menia', 'phone_number' => '029', 'shipping_fee' => 700],
            ['name' => 'Aflou', 'phone_number' => '027', 'shipping_fee' => 600],
            ['name' => 'El Abiodh Sidi Cheikh', 'phone_number' => '049', 'shipping_fee' => 700],
            ['name' => 'El Aricha', 'phone_number' => '043', 'shipping_fee' => 600],
            ['name' => 'El Kantara', 'phone_number' => '033', 'shipping_fee' => 600],
            ['name' => 'Barika', 'phone_number' => '033', 'shipping_fee' => 600],
            ['name' => 'Bou Saâda', 'phone_number' => '035', 'shipping_fee' => 600],
            ['name' => 'Bir El Ater', 'phone_number' => '037', 'shipping_fee' => 600],
            ['name' => 'Ksar El Boukhari', 'phone_number' => '025', 'shipping_fee' => 600],
            ['name' => 'Ksar Chellala', 'phone_number' => '046', 'shipping_fee' => 600],
            ['name' => 'Aïn Oussera', 'phone_number' => '027', 'shipping_fee' => 600],
            ['name' => 'Messaad', 'phone_number' => '027', 'shipping_fee' => 600],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->data() as $wilaya) {
            Wilaya::firstOrCreate(
                ['name' => $wilaya['name']],
                $wilaya,
            );
        }
    }
}
