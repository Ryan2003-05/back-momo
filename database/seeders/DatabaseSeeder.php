<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Operateur;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //  Créer les 3 opérateurs (RG15) 
        $operateurs = [
            ['nom' => 'MTN',    'actif' => true],
            ['nom' => 'Moov',   'actif' => true],
            ['nom' => 'Celtis', 'actif' => true],
        ];

        foreach ($operateurs as $op) {
            Operateur::firstOrCreate(['nom' => $op['nom']], $op);
        }

        // Créer les comptes admin
        $admins = [
            [
                'email'        => 'admin@ryanpaycom.bj',
                'mot_de_passe' => Hash::make('Admin@Ryan'),
                'role'         => 'admin',
            ],
            [
                'email'        => 'admin@gregpaycom.bj',
                'mot_de_passe' => Hash::make('Admin@Greg'),
                'role'         => 'admin',
            ],
        ];

        foreach ($admins as $admin) {
            User::firstOrCreate(
                ['email' => $admin['email']],
                $admin
            );
        }

        $this->command->info('✅ Opérateurs et admins créés avec succès.');
    }
}