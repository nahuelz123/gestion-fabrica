<?php

namespace Tests\Feature;

use App\Livewire\Vending\MachineForm;
use App\Livewire\Vending\PartnerForm;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\VendingMachine;
use App\Models\VendingPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendingSimpleUxTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->company = Company::create(['name' => 'Rapi Burguer']);
        $this->owner = User::create([
            'company_id' => $this->company->id,
            'name' => 'Dueño',
            'email' => 'dueno@rapiburguer.test',
            'password' => 'secret123',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_create_commerce_without_typing_coordinates(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PartnerForm::class)
            ->set('name', 'Kiosco Independencia')
            ->set('street_name', 'Independencia')
            ->set('street_number', '1234')
            ->set('city_name', 'Mar del Plata')
            ->set('state_name', 'Buenos Aires')
            ->set('commission_percent', '15')
            ->call('save')
            ->assertHasNoErrors();

        $partner = VendingPartner::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('Kiosco Independencia', $partner->name);
        $this->assertSame('Independencia 1234, Mar del Plata, Buenos Aires', $partner->address);
        $this->assertNull($partner->latitude);
        $this->assertNull($partner->longitude);
    }

    public function test_new_machine_only_needs_business_product_price_and_loaded_units(): void
    {
        $unit = Unit::create(['name' => 'Unidad', 'abbreviation' => 'u', 'type' => 'count']);
        $category = ProductCategory::create([
            'company_id' => $this->company->id,
            'name' => 'Terminados',
        ]);
        $product = Product::create([
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'type' => 'finished_product',
            'name' => 'Hamburguesa cheddar',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'status' => 'active',
        ]);
        $partner = VendingPartner::create([
            'company_id' => $this->company->id,
            'name' => 'Kiosco Centro',
            'street_name' => 'Belgrano',
            'street_number' => '2000',
            'city_name' => 'Mar del Plata',
            'state_name' => 'Buenos Aires',
            'address' => 'Belgrano 2000, Mar del Plata, Buenos Aires',
            'commission_percent' => 15,
            'status' => 'active',
        ]);

        Livewire::actingAs($this->owner)
            ->test(MachineForm::class)
            ->set('vending_partner_id', $partner->id)
            ->set('product_id', $product->id)
            ->set('sale_price', '8500')
            ->set('loaded_units', '40')
            ->call('save')
            ->assertHasNoErrors();

        $machine = VendingMachine::where('company_id', $this->company->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/^MAQ-\d{3}$/', $machine->code);
        $this->assertSame('Máquina Kiosco Centro', $machine->name);
        $this->assertSame(8500.0, (float) $machine->sale_price);
        $this->assertSame(40, $machine->loaded_units);
        $this->assertSame($partner->id, $machine->vending_partner_id);
        $this->assertSame($product->id, $machine->product_id);
    }
}
