<?php

namespace App\Livewire\Vending;

use App\Models\VendingPartner;
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
    public string $street_name = '';
    public string $street_number = '';
    public string $city_name = 'Mar del Plata';
    public string $state_name = 'Buenos Aires';
    public string $latitude = '';
    public string $longitude = '';
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
        foreach (['name', 'street_name', 'street_number', 'city_name', 'state_name', 'latitude', 'longitude'] as $field) {
            $this->{$field} = (string) ($partner->{$field} ?? '');
        }
    }

    public function save(MercadoPagoVendingService $service): void
    {
        $this->persist($service, false);
    }

    public function saveAndConnect(MercadoPagoVendingService $service): void
    {
        $this->persist($service, true);
    }

    private function persist(MercadoPagoVendingService $service, bool $connect): void
    {
        Gate::authorize('owner-only');

        $rules = [
            'name' => 'required|string|max:255',
            'street_name' => 'required|string|max:255',
            'street_number' => 'required|string|max:50',
            'city_name' => 'required|string|max:255',
            'state_name' => 'required|string|max:255',
        ];

        if ($connect) {
            $rules['latitude'] = 'required|numeric|between:-90,90';
            $rules['longitude'] = 'required|numeric|between:-180,180';
        } else {
            $rules['latitude'] = 'nullable|numeric|between:-90,90';
            $rules['longitude'] = 'nullable|numeric|between:-180,180';
        }

        $data = $this->validate($rules, [
            'latitude.required' => 'Necesitamos la ubicación del comercio para vincular Mercado Pago.',
            'longitude.required' => 'Necesitamos la ubicación del comercio para vincular Mercado Pago.',
        ]);

        $data['company_id'] = auth()->user()->company_id;
        $data['address'] = trim("{$this->street_name} {$this->street_number}, {$this->city_name}, {$this->state_name}");
        $data['latitude'] = $this->latitude !== '' ? (float) $this->latitude : null;
        $data['longitude'] = $this->longitude !== '' ? (float) $this->longitude : null;
        $data['commission_percent'] = 0;

        $partner = $this->partnerId
            ? VendingPartner::where('company_id', auth()->user()->company_id)->findOrFail($this->partnerId)
            : new VendingPartner();

        $partner->fill($data)->save();
        $this->partnerId = $partner->id;

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
                session()->flash('error', 'El comercio se guardó, pero Mercado Pago todavía no pudo sincronizar la sucursal: ' . $e->getMessage());
                $this->redirect(route('vending.partners.edit', $partner->id), navigate: true);
                return;
            }
        }

        session()->flash('message', 'Comercio guardado correctamente.');

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

    public function render()
    {
        $partner = $this->partnerId
            ? VendingPartner::where('company_id', auth()->user()->company_id)->find($this->partnerId)
            : null;

        return view('livewire.vending.partner-form', compact('partner'));
    }
}
