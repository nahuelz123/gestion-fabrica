<?php

namespace Tests\Feature;

use App\Livewire\Vending\Index as VendingIndex;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_owner_can_create_kiosk_with_only_basic_address(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PartnerForm::class)
            ->set('name', 'Kiosco Independencia')
            ->set('address_query', 'Independencia 1234')
            ->set('city_name', 'Mar del Plata')
            ->set('state_name', 'Buenos Aires')
            ->call('save')
            ->assertHasNoErrors();

        $partner = VendingPartner::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('Kiosco Independencia', $partner->name);
        $this->assertSame('Independencia 1234, Mar del Plata, Buenos Aires', $partner->address);
        $this->assertSame(0.0, (float) $partner->commission_percent);
        $this->assertNull($partner->latitude);
        $this->assertNull($partner->longitude);
    }

    public function test_linking_kiosk_resolves_written_intersection_without_device_gps(): void
    {
        config([
            'services.mercadopago.client_id' => 'client-test',
            'services.mercadopago.client_secret' => 'secret-test',
            'services.mercadopago.redirect_uri' => 'https://example.test/mercadopago/oauth/callback',
            'services.mercadopago.webhook_secret' => 'webhook-test',
        ]);

        Cache::flush();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'nominatim.openstreetmap.org/search')) {
                return Http::response([[
                    'lat' => '-38.0001000',
                    'lon' => '-57.5502000',
                    'display_name' => 'Belgrano e Independencia, Mar del Plata, Buenos Aires, Argentina',
                    'address' => [
                        'road' => 'Belgrano',
                        'city' => 'Mar del Plata',
                        'state' => 'Buenos Aires',
                    ],
                ]], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $component = Livewire::actingAs($this->owner)
            ->test(PartnerForm::class)
            ->set('name', 'Kiosco Belgrano')
            ->set('address_query', 'Belgrano e Independencia')
            ->set('city_name', 'Mar del Plata')
            ->set('state_name', 'Buenos Aires')
            ->call('saveAndConnect')
            ->assertHasNoErrors();

        $partner = VendingPartner::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('Belgrano', $partner->street_name);
        $this->assertSame('S/N', $partner->street_number);
        $this->assertSame(-38.0001, (float) $partner->latitude);
        $this->assertSame(-57.5502, (float) $partner->longitude);
        $this->assertSame('Belgrano e Independencia', $partner->location_reference);
        $component->assertRedirect(route('vending.mercadopago.connect', [
            'partner' => $partner->id,
            'return' => 'partners',
        ]));
    }

    public function test_kiosk_form_does_not_request_browser_geolocation(): void
    {
        $this->actingAs($this->owner)
            ->get(route('vending.partners.create'))
            ->assertOk()
            ->assertSee('Dirección o esquina')
            ->assertSee('No usamos la ubicación actual de tu teléfono.')
            ->assertDontSee('navigator.geolocation', false);
    }

    public function test_missing_global_mercadopago_config_is_explained_without_500(): void
    {
        config([
            'services.mercadopago.client_id' => null,
            'services.mercadopago.client_secret' => null,
            'services.mercadopago.redirect_uri' => null,
            'services.mercadopago.webhook_secret' => null,
        ]);

        $partner = VendingPartner::create([
            'company_id' => $this->company->id,
            'name' => 'Kiosco Sin MP',
            'address' => 'Belgrano e Independencia, Mar del Plata, Buenos Aires',
            'city_name' => 'Mar del Plata',
            'state_name' => 'Buenos Aires',
            'commission_percent' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($this->owner)
            ->get(route('vending.mercadopago.connect', ['partner' => $partner->id]))
            ->assertRedirect(route('vending.partners.edit', ['id' => $partner->id, 'return' => 'partners']))
            ->assertSessionHas('error');

        $this->actingAs($this->owner)
            ->get(route('vending.partners.edit', $partner->id))
            ->assertOk()
            ->assertSee('Falta la configuración general de Mercado Pago')
            ->assertSee('Mercado Pago pendiente de configuración');
    }

    public function test_owner_can_pause_machine_and_public_tablet_stops_working(): void
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
            'name' => 'Hamburguesa bacon',
            'presentation' => 'unidad',
            'base_unit_id' => $unit->id,
            'status' => 'active',
        ]);
        $partner = VendingPartner::create([
            'company_id' => $this->company->id,
            'name' => 'Kiosco Pausa',
            'address' => 'Centro',
            'commission_percent' => 0,
            'status' => 'active',
        ]);
        $machine = VendingMachine::create([
            'company_id' => $this->company->id,
            'vending_partner_id' => $partner->id,
            'product_id' => $product->id,
            'code' => 'MAQ-PAUSA',
            'name' => 'Máquina pausa',
            'sale_price' => 8500,
            'status' => 'active',
        ]);

        $this->get(route('vending.tablet', ['token' => $machine->public_token]))
            ->assertOk();

        Livewire::actingAs($this->owner)
            ->test(VendingIndex::class)
            ->call('toggleStatus', $machine->id);

        $this->assertSame('inactive', $machine->fresh()->status);

        $this->get(route('vending.tablet', ['token' => $machine->public_token]))
            ->assertNotFound();
    }

    public function test_new_machine_only_needs_kiosk_product_and_price(): void
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
            'commission_percent' => 0,
            'status' => 'active',
        ]);

        Livewire::actingAs($this->owner)
            ->test(MachineForm::class)
            ->set('vending_partner_id', $partner->id)
            ->set('product_id', $product->id)
            ->set('sale_price', '8500')
            ->call('save')
            ->assertHasNoErrors();

        $machine = VendingMachine::where('company_id', $this->company->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/^MAQ-\d{3}$/', $machine->code);
        $this->assertSame('Máquina Kiosco Centro', $machine->name);
        $this->assertSame(8500.0, (float) $machine->sale_price);
        $this->assertSame(0, $machine->loaded_units);
        $this->assertSame($partner->id, $machine->vending_partner_id);
        $this->assertSame($product->id, $machine->product_id);
    }
}
