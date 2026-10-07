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
        $company = Company::firstOrFail();

        $defaultPassword = (string) env('SEED_DEFAULT_PASSWORD', '');
        if (app()->environment('production') && $defaultPassword === '') {
            throw new \RuntimeException('UserSeeder no puede crear credenciales demo en producción. Definí SEED_DEFAULT_PASSWORD explícitamente o no ejecutes DatabaseSeeder.');
        }
        if ($defaultPassword === '') {
            $defaultPassword = 'password';
        }

        User::updateOrCreate(
            ['email' => 'admin@fabrica.com'],
            [
                'company_id' => $company->id,
                'name' => 'Administrador',
                'phone' => '1111111111',
                'password' => $defaultPassword,
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
