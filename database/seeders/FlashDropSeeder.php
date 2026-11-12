<?php

namespace Database\Seeders;

use App\Models\Drop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

class FlashDropSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate: kayıt varsa hiçbir alan ezilmez (uuid, available_stock dahil).
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')],
        );

        $drops = [
            'FD-ACTIVE-001' => [
                'title' => 'Active Flash Drop',
                'price_cents' => 4999, 'total_stock' => 50, 'available_stock' => 50,
                'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(7),
            ],
            'FD-UPCOMING-001' => [
                'title' => 'Upcoming Flash Drop',
                'price_cents' => 7999, 'total_stock' => 30, 'available_stock' => 30,
                'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(9),
            ],
            'FD-EXPIRED-001' => [
                'title' => 'Expired Flash Drop',
                'price_cents' => 2999, 'total_stock' => 20, 'available_stock' => 20,
                'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(3),
            ],
        ];

        foreach ($drops as $sku => $attributes) {
            $drop = Drop::firstOrCreate(['sku' => $sku], $attributes);

            // Redis stok sayacı: yalnızca anahtar yoksa yaz (SETNX), DB'deki güncel stokla.
            Redis::setnx("drop:{$drop->uuid}:available_stock", $drop->available_stock);

            // Pencere meta verisi (ileride middleware'in sıfır-SQL kontrolü için).
            Redis::hmset("drop:{$drop->uuid}:meta", [
                'starts_at' => $drop->starts_at->getTimestamp(),
                'ends_at' => $drop->ends_at->getTimestamp(),
            ]);
        }
    }
}
