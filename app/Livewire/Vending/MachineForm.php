<?php

namespace App\Livewire\Vending;

use App\Models\Product;
use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Services\MercadoPagoVendingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class MachineForm extends Component
{
    public ?int $machineId = null;
    public string $code = '';
    public string $name = '';
    public ?int $vending_partner_id = null;
    public ?int $product_id = null;
    public string $location = '';
    public string $sale_price = '';
    public string $capacity = '';
    public string $loaded_units = '0';
    public string $status = 'active';

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');
        if (!$id) return;

        $machine = VendingMachine::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $this->machineId = $machine->id;
        $this->code = $machine->code;
        $this->name = $machine->name;
        $this->vending_partner_id = $machine->vending_partner_id;
        $this->product_id = $machine->product_id;
        $this->location = (string) $machine->location;
        $this->sale_price = (string) $machine->sale_price;
        $this->capacity = (string) ($machine->capacity ?? '');
        $this->loaded_units = (string) $machine->loaded_units;
        $this->status = $machine->status;
    }

    public function save(MercadoPagoVendingService $service): void
    {
        $companyId = auth()->user()->company_id;
        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('vending_machines', 'code')->where('company_id', $companyId)->ignore($this->machineId)],
            'name' => 'required|string|max:255',
            'vending_partner_id' => ['required', 'integer', Rule::exists('vending_partners', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'location' => 'nullable|string|max:255',
            'sale_price' => 'required|numeric|min:1|max:99999999',
            'capacity' => 'nullable|integer|min:1|max:100000',
            'loaded_units' => 'required|integer|min:0|max:100000',
            'status' => 'required|in:active,inactive',
        ]);

        $partner = VendingPartner::where('company_id', $companyId)->findOrFail($this->vending_partner_id);
        $product = Product::where('company_id', $companyId)->findOrFail($this->product_id);
        abort_unless(($product->type instanceof \BackedEnum ? $product->type->value : $product->type) === 'finished_product', 422);

        if ($this->capacity !== '' && (int) $this->loaded_units > (int) $this->capacity) {
            $this->addError('loaded_units', 'El stock dentro de la máquina no puede superar su capacidad.');
            return;
        }

        $machine = $this->machineId
            ? VendingMachine::where('company_id', $companyId)->with('partner')->findOrFail($this->machineId)
            : new VendingMachine();

        $forceOrder = !$machine->exists
            || (float) $machine->sale_price !== (float) $this->sale_price
            || (int) $machine->vending_partner_id !== (int) $this->vending_partner_id
            || (int) $machine->product_id !== (int) $this->product_id;

        // Si cambia el cobro, el comercio o la máquina queda vacía/inactiva,
        // primero anulamos la order anterior en Mercado Pago. Así un QR guardado
        // no puede seguir cobrando un precio viejo o vender sin stock.
        if ($machine->exists
            && ($forceOrder || $this->status !== 'active' || (int) $this->loaded_units <= 0)
            && $machine->partner?->hasMercadoPagoConnection()) {
            try {
                $service->cancelActiveOrders($machine);
            } catch (Throwable $e) {
                report($e);
                $this->addError('sale_price', $e->getMessage());
                return;
            }
        }

        $data['company_id'] = $companyId;
        $data['capacity'] = $this->capacity !== '' ? (int) $this->capacity : null;
        $data['sale_price'] = (float) $this->sale_price;
        $data['loaded_units'] = (int) $this->loaded_units;
        $machine->fill($data)->save();

        if ($machine->status === 'active' && $machine->loaded_units > 0 && $partner->hasMercadoPagoConnection()) {
            try {
                $service->provisionMachine($machine->fresh(['partner', 'product']), false);
                session()->flash('message', 'Máquina guardada y QR de Mercado Pago sincronizado.');
            } catch (Throwable $e) {
                report($e);
                session()->flash('error', 'La máquina se guardó, pero todavía no quedó lista para cobrar: ' . $e->getMessage());
            }
        } elseif ($machine->loaded_units <= 0) {
            session()->flash('message', 'Máquina guardada sin stock. La tablet no mostrará el QR hasta que cargues unidades.');
        } else {
            session()->flash('message', 'Máquina guardada. Vinculá Mercado Pago al comercio para activar el QR.');
        }

        $this->redirect(route('vending.index'), navigate: true);
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $partners = VendingPartner::where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'mercadopago_user_id']);
        $products = Product::where('company_id', $companyId)->where('type', 'finished_product')->orderBy('name')->get(['id', 'name']);

        return view('livewire.vending.machine-form', compact('partners', 'products'));
    }
}
