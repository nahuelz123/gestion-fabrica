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
    public string $partnerSearch = '';
    public string $productSearch = '';
    public string $location = '';
    public string $sale_price = '';
    public string $capacity = '';
    public string $loaded_units = '0';
    public string $status = 'active';

    public function mount(?int $id = null): void
    {
        Gate::authorize('owner-only');

        if (!$id) {
            $partnerId = request()->integer('partner');
            if ($partnerId > 0 && VendingPartner::where('company_id', auth()->user()->company_id)->whereKey($partnerId)->exists()) {
                $this->vending_partner_id = $partnerId;
            }
            return;
        }

        $machine = VendingMachine::where('company_id', auth()->user()->company_id)
            ->with(['partner:id,name', 'product:id,name'])
            ->findOrFail($id);
        $this->machineId = $machine->id;
        $this->code = $machine->code;
        $this->name = $machine->name;
        $this->vending_partner_id = $machine->vending_partner_id;
        $this->product_id = $machine->product_id;
        $this->partnerSearch = (string) ($machine->partner?->name ?? '');
        $this->productSearch = (string) ($machine->product?->name ?? '');
        $this->location = (string) $machine->location;
        $this->sale_price = (string) $machine->sale_price;
        $this->capacity = (string) ($machine->capacity ?? '');
        $this->loaded_units = (string) $machine->loaded_units;
        $this->status = $machine->status;
    }

    public function save(MercadoPagoVendingService $service): void
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;

        $this->validate([
            'vending_partner_id' => ['required', 'integer', Rule::exists('vending_partners', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('type', 'finished_product')],
        ], [
            'vending_partner_id.required' => 'Elegí el comercio donde está la máquina.',
            'product_id.required' => 'Elegí la hamburguesa que vende la máquina.',
        ]);

        $partner = VendingPartner::where('company_id', $companyId)->findOrFail($this->vending_partner_id);
        $product = Product::where('company_id', $companyId)->where('type', 'finished_product')->findOrFail($this->product_id);

        if (trim($this->code) === '') {
            $this->code = $this->generateCode($companyId);
        }
        if (trim($this->name) === '') {
            $this->name = 'Máquina ' . $partner->name;
        }

        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('vending_machines', 'code')->where('company_id', $companyId)->ignore($this->machineId)],
            'name' => 'required|string|max:255',
            'vending_partner_id' => ['required', 'integer', Rule::exists('vending_partners', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('type', 'finished_product')],
            'location' => 'nullable|string|max:255',
            'sale_price' => 'required|numeric|min:1|max:99999999',
            'capacity' => 'nullable|integer|min:1|max:100000',
            'loaded_units' => 'required|integer|min:0|max:100000',
            'status' => 'required|in:active,inactive',
        ], [
            'sale_price.required' => 'Ingresá el precio de venta.',
            'loaded_units.required' => 'Indicá cuántas hamburguesas cargaste en la máquina.',
        ]);

        if ($this->capacity !== '' && (int) $this->loaded_units > (int) $this->capacity) {
            $this->addError('loaded_units', 'Las hamburguesas cargadas no pueden superar la capacidad de la máquina.');
            return;
        }

        $machine = $this->machineId
            ? VendingMachine::where('company_id', $companyId)->with('partner')->findOrFail($this->machineId)
            : new VendingMachine();

        $forceOrder = !$machine->exists
            || (float) $machine->sale_price !== (float) $this->sale_price
            || (int) $machine->vending_partner_id !== (int) $this->vending_partner_id
            || (int) $machine->product_id !== (int) $this->product_id;

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

        if (!$partner->hasMercadoPagoConnection()) {
            session()->flash('message', 'Máquina guardada. Falta un solo paso: vincular Mercado Pago del comercio.');
            $this->redirect(route('vending.partners.edit', ['id' => $partner->id, 'return' => 'machines']), navigate: true);
            return;
        }

        if ($machine->status === 'active' && $machine->loaded_units > 0) {
            try {
                $service->provisionMachine($machine->fresh(['partner', 'product']), false);
                session()->flash('message', 'Máquina lista. El QR de Mercado Pago quedó sincronizado.');
            } catch (Throwable $e) {
                report($e);
                session()->flash('error', 'La máquina se guardó, pero todavía no quedó lista para cobrar: ' . $e->getMessage());
            }
        } elseif ($machine->loaded_units <= 0) {
            session()->flash('message', 'Máquina guardada sin stock. El QR se mostrará cuando cargues unidades.');
        } else {
            session()->flash('message', 'Máquina guardada.');
        }

        $this->redirect(route('vending.index'), navigate: true);
    }

    private function generateCode(int $companyId): string
    {
        $next = ((int) VendingMachine::where('company_id', $companyId)->max('id')) + 1;
        do {
            $code = 'MAQ-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (VendingMachine::where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    public function render()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        $partnerSearch = trim($this->partnerSearch);
        $productSearch = trim($this->productSearch);
        $partnerCount = VendingPartner::where('company_id', $companyId)->where('status', 'active')->count();
        $productCount = Product::where('company_id', $companyId)->where('type', 'finished_product')->where('status', 'active')->count();

        $partners = VendingPartner::query()
            ->where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('status', 'active');
                if ($this->vending_partner_id) $q->orWhere('id', $this->vending_partner_id);
            })
            ->when($partnerSearch !== '', fn ($q) => $q->where('name', 'like', "%{$partnerSearch}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'mercadopago_user_id']);

        if ($this->vending_partner_id && !$partners->contains('id', $this->vending_partner_id)) {
            $selected = VendingPartner::where('company_id', $companyId)->find($this->vending_partner_id, ['id', 'name', 'mercadopago_user_id']);
            if ($selected) $partners->prepend($selected);
        }

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('type', 'finished_product')
            ->when($productSearch !== '', function ($q) use ($productSearch) {
                $q->where(function ($sq) use ($productSearch) {
                    $sq->where('name', 'like', "%{$productSearch}%")
                        ->orWhere('internal_code', 'like', "{$productSearch}%")
                        ->orWhereHas('aliases', fn ($aq) => $aq->where('alias', 'like', "%{$productSearch}%"));
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'internal_code']);

        if ($this->product_id && !$products->contains('id', $this->product_id)) {
            $selected = Product::where('company_id', $companyId)
                ->where('type', 'finished_product')
                ->find($this->product_id, ['id', 'name', 'internal_code']);
            if ($selected) $products->prepend($selected);
        }

        $selectedPartner = $this->vending_partner_id
            ? VendingPartner::where('company_id', $companyId)->find($this->vending_partner_id)
            : null;

        return view('livewire.vending.machine-form', compact('partners', 'products', 'selectedPartner', 'partnerCount', 'productCount'));
    }
}
