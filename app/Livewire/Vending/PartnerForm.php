<?php

namespace App\Livewire\Vending;

use App\Models\VendingPartner;
use App\Services\AddressGeocodingService;
use App\Services\MercadoPagoVendingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class PartnerForm extends Component
{
    public ?int $partnerId = null;
    public string $name = '';
    public string $address_query = '';
    public string $city_name = 'Mar del Plata';
    public string $state_name = 'Buenos Aires';
    public bool $returnToMachine = false;
    public bool $returnToMachines = false;

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');
        $return = request()->query('return');
        $this->returnToMachine = $return === 'machine';
        $this->returnToMachines = $return === 'machines';

        if (!$id) return;

        $partner = VendingPartner::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $this->partnerId = $partner->id;
        $this->name = (string) $partner->name;
        $this->city_name = (string) ($partner->city_name ?: 'Mar del Plata');
        $this->state_name = (string) ($partner->state_name ?: 'Buenos Aires');
        $this->address_query = $this->shortAddress($partner);
    }

    public function save(MercadoPagoVendingService $service, AddressGeocodingService $geocoder): void
    {
        $this->persist($service, $geocoder, false);
    }

    public function saveAndConnect(MercadoPagoVendingService $service, AddressGeocodingService $geocoder): void
    {
        $this->persist($service, $geocoder, true);
    }

    private function persist(
        MercadoPagoVendingService $service,
        AddressGeocodingService $geocoder,
        bool $connect
    ): void {
        Gate::authorize('owner-only');

        $data = $this->validate([
            'name' => 'required|string|max:255',
            'address_query' => 'required|string|max:255',
            'city_name' => 'required|string|max:255',
            'state_name' => 'required|string|max:255',
        ], [], [
            'name' => 'nombre del kiosco',
            'address_query' => 'dirección o esquina',
            'city_name' => 'ciudad',
            'state_name' => 'provincia',
        ]);

        $partner = $this->partnerId
            ? VendingPartner::where('company_id', auth()->user()->company_id)->findOrFail($this->partnerId)
            : new VendingPartner();

        $configurationMissing = $connect && !$this->mercadoPagoIntegrationReady();
        if ($configurationMissing) {
            $connect = false;
        }

        $payload = [
            'company_id' => auth()->user()->company_id,
            'name' => trim($data['name']),
            'address' => trim("{$data['address_query']}, {$data['city_name']}, {$data['state_name']}"),
            'city_name' => trim($data['city_name']),
            'state_name' => trim($data['state_name']),
            'commission_percent' => 0,
            'status' => $partner->exists ? $partner->status : 'active',
        ];

        if ($connect) {
            try {
                $location = $geocoder->resolve(
                    (string) $data['address_query'],
                    (string) $data['city_name'],
                    (string) $data['state_name'],
                );
            } catch (Throwable $e) {
                report($e);
                $this->addError('address_query', $e->getMessage());
                return;
            }

            $payload = array_merge($payload, [
                'street_name' => $location['street_name'],
                'street_number' => $location['street_number'],
                'city_name' => $location['city_name'],
                'state_name' => $location['state_name'],
                'location_reference' => $location['location_reference'],
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ]);
        } elseif (!$partner->exists || !$partner->hasMercadoPagoConnection()) {
            // Se puede guardar el kiosco antes de vincularlo. La ubicación técnica
            // se resuelve recién al conectar Mercado Pago, sin usar el GPS del teléfono.
            $payload = array_merge($payload, [
                'street_name' => null,
                'street_number' => null,
                'location_reference' => null,
                'latitude' => null,
                'longitude' => null,
            ]);
        }

        $partner->fill($payload)->save();
        $this->partnerId = $partner->id;

        if ($configurationMissing) {
            session()->flash(
                'error',
                'El kiosco quedó guardado. Falta configurar una sola vez la integración general de Mercado Pago de Rapi Burguer antes de vincular cuentas de kioscos.'
            );
            $return = $this->returnToMachine ? 'machine' : ($this->returnToMachines ? 'machines' : 'partners');
            $this->redirect(route('vending.partners.edit', ['id' => $partner->id, 'return' => $return]), navigate: true);
            return;
        }

        if ($connect) {
            $return = $this->returnToMachine ? 'machine' : ($this->returnToMachines ? 'machines' : 'partners');
            $this->redirect(route('vending.mercadopago.connect', ['partner' => $partner->id, 'return' => $return]), navigate: false);
            return;
        }

        if ($partner->hasMercadoPagoConnection()) {
            try {
                $service->provisionPartnerStore($partner->fresh());
            } catch (Throwable $e) {
                report($e);
                session()->flash('error', 'El kiosco se guardó, pero Mercado Pago todavía no pudo sincronizar la sucursal: ' . $e->getMessage());
                $this->redirect(route('vending.partners.edit', $partner->id), navigate: true);
                return;
            }
        }

        session()->flash('message', 'Kiosco guardado correctamente.');

        if ($this->returnToMachine) {
            $this->redirect(route('vending.machines.create', ['partner' => $partner->id]), navigate: true);
            return;
        }

        if ($this->returnToMachines) {
            $this->redirect(route('vending.index'), navigate: true);
            return;
        }

        $this->redirect(route('vending.partners.index'), navigate: true);
    }

    private function mercadoPagoIntegrationReady(): bool
    {
        return filled(config('services.mercadopago.client_id'))
            && filled(config('services.mercadopago.client_secret'))
            && filled(config('services.mercadopago.redirect_uri'))
            && filled(config('services.mercadopago.webhook_secret'));
    }

    private function shortAddress(VendingPartner $partner): string
    {
        if ($partner->address) {
            $address = (string) $partner->address;
            $suffix = ', ' . ($partner->city_name ?: 'Mar del Plata') . ', ' . ($partner->state_name ?: 'Buenos Aires');
            if (str_ends_with(mb_strtolower($address), mb_strtolower($suffix))) {
                return trim(mb_substr($address, 0, mb_strlen($address) - mb_strlen($suffix)));
            }
            return trim((string) explode(',', $address, 2)[0]);
        }

        return trim((string) ($partner->street_name . ' ' . $partner->street_number));
    }

    public function render()
    {
        $partner = $this->partnerId
            ? VendingPartner::where('company_id', auth()->user()->company_id)->find($this->partnerId)
            : null;
        $mercadoPagoConfigured = $this->mercadoPagoIntegrationReady();

        return view('livewire.vending.partner-form', compact('partner', 'mercadoPagoConfigured'));
    }
}
