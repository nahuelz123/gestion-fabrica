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
    public ?int $vending_partner_id = null;
    public ?int $product_id = null;
    public string $partnerSearch = '';
    public string $productSearch = '';
    public string $sale_price = '';

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
        $this->vending_partner_id = $machine->vending_partner_id;
        $this->product_id = $machine->product_id;
        $this->partnerSearch = (string) ($machine->partner?->name ?? '');
        $this->productSearch = (string) ($machine->product?->name ?? '');
        $this->sale_price = (string) $machine->sale_price;
    }

    public function save(MercadoPagoVendingService $service): void
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;

        $data = $this->validate([
            'vending_partner_id' => ['required', 'integer', Rule::exists('vending_partners', 'id')->where('company_id', $companyId)],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->where('type', 'finished_product')],
            'sale_price' => 'required|numeric|min:1|max:99999999',
        ], [
            'vending_partner_id.required' => 'Elegí el comercio donde está la máquina.',
            'product_id.required' => 'Elegí la hamburguesa que vende la máquina.',
            'sale_price.required' => 'Ingresá el precio de venta.',
        ]);

        $partner = VendingPartner::where('company_id', $companyId)->findOrFail($this->vending_partner_id);
        $product = Product::where('company_id', $companyId)->where('type', 'finished_product')->findOrFail($this->product_id);

        $machine = $this->machineId
            ? VendingMachine::where('company_id', $companyId)->with('partner')->findOrFail($this->machineId)
            : new VendingMachine();

        $partnerChanged = $machine->exists
            && (int) $machine->vending_partner_id !== (int) $this->vending_partner_id;

        $forceOrder = !$machine->exists
            || (float) $machine->sale_price !== (float) $this->sale_price
            || $partnerChanged
            || (int) $machine->product_id !== (int) $this->product_id;

        if ($machine->exists && $forceOrder && $machine->partner?->hasMercadoPagoConnection()) {
            try {
                $service->cancelActiveOrders($machine);
            } catch (Throwable $e) {
                report($e);
                $this->addError('sale_price', $e->getMessage());
                return;
            }
        }

        if (!$machine->exists) {
            $machine->code = $this->generateCode($companyId);
            $machine->name = 'Máquina ' . $partner->name;
            $machine->status = 'active';
        } elseif ($partnerChanged) {
            $machine->name = 'Máquina ' . $partner->name;
            $machine->mercadopago_pos_id = null;
            $machine->mercadopago_external_pos_id = null;
            $machine->mercadopago_qr_image_url = null;
            $machine->mercadopago_qr_template_image_url = null;
            $machine->mercadopago_qr_code = null;
            $machine->last_provisioned_at = null;
        }

        $machine->fill([
            'company_id' => $companyId,
            'vending_partner_id' => $partner->id,
            'product_id' => $product->id,
            'sale_price' => (float) $data['sale_price'],
        ])->save();

        if (!$partner->hasMercadoPagoConnection()) {
            session()->flash('message', 'Máquina guardada. Ahora vinculá Mercado Pago del comercio para empezar a registrar ventas.');
            $this->redirect(route('vending.partners.edit', ['id' => $partner->id, 'return' => 'machines']), navigate: true);
            return;
        }

        if ($machine->status === 'active') {
            try {
                $service->provisionMachine($machine->fresh(['partner', 'product']), $forceOrder);
                session()->flash('message', 'Máquina lista. Cada pago aprobado quedará registrado como una venta.');
            } catch (Throwable $e) {
                report($e);
                session()->flash('error', 'La máquina se guardó, pero todavía no quedó lista para cobrar: ' . $e->getMessage());
            }
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
