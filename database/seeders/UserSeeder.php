<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        User::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'email' => 'admin@fabrica.com',
            'phone' => '1111111111',
            'password' => 'password',
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ]);

        User::create([
            'company_id' => $company->id,
            'name' => 'Encargado',
            'email' => 'encargado@fabrica.com',
            'phone' => '2222222222',
            'password' => 'password',
            'role' => UserRole::Manager,
            'status' => UserStatus::Active,
        ]);
    }
}
