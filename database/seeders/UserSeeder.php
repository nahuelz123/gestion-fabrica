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

        User::updateOrCreate(
            ['email' => 'admin@fabrica.com'],
            [
                'company_id' => $company->id,
                'name' => 'Administrador',
                'phone' => '1111111111',
                'password' => 'password',
                'role' => UserRole::Owner,
                'status' => UserStatus::Active,
            ]
        );

        User::updateOrCreate(
            ['email' => 'encargado@fabrica.com'],
            [
                'company_id' => $company->id,
                'name' => 'Encargado',
                'phone' => '2222222222',
                'password' => 'password',
                'role' => UserRole::Manager,
                'status' => UserStatus::Active,
            ]
        );
    }
}
