<?php

namespace App\Livewire\Vending;

use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Models\VendingSale;
use App\Services\MercadoPagoVendingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    use WithPagination;

    public string $search = '';
    public string $partnerFilter = '';

    public function mount(): void
    {
        Gate::authorize('owner-only');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('machinesPage');
        $this->resetPage('salesPage');
    }

    public function updatedPartnerFilter(): void
    {
        $this->resetPage('machinesPage');
        $this->resetPage('salesPage');
    }

    public function provisionMachine(int $machineId, MercadoPagoVendingService $service): void
    {
        Gate::authorize('owner-only');

        $machine = VendingMachine::where('company_id', auth()->user()->company_id)
            ->with(['partner', 'product'])
            ->findOrFail($machineId);

        try {
            $service->provisionMachine($machine, true);
            session()->flash('message', "QR de {$machine->name} preparado correctamente.");
        } catch (Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());
        }
    }

    public function settlePartner(int $partnerId): void
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;

        $partner = VendingPartner::where('company_id', $companyId)->findOrFail($partnerId);

        $count = DB::transaction(function () use ($companyId, $partner) {
            return VendingSale::where('company_id', $companyId)
                ->where('vending_partner_id', $partner->id)
                ->whereNull('settled_at')
                ->whereIn('status', ['approved', 'partially_refunded'])
                ->update([
                    'settled_at' => now(),
                    'settled_by_user_id' => auth()->id(),
                    'updated_at' => now(),
                ]);
        });

        session()->flash('message', $count > 0
            ? "Se marcaron {$count} ventas de {$partner->name} como liquidadas."
            : "{$partner->name} no tiene ventas pendientes de liquidación.");
    }

    public function render()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        $search = trim($this->search);
        $partnerId = ctype_digit($this->partnerFilter) ? (int) $this->partnerFilter : null;

        $partners = VendingPartner::where('company_id', $companyId)
            ->withCount('machines')
            ->orderBy('name')
            ->get(['id', 'name', 'commission_percent', 'mercadopago_user_id', 'mercadopago_access_token', 'status']);

        $machines = VendingMachine::query()
            ->where('company_id', $companyId)
            ->with(['partner:id,name,mercadopago_user_id,mercadopago_access_token', 'product:id,name'])
            ->when($partnerId, fn ($q) => $q->where('vending_partner_id', $partnerId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhereHas('partner', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('name')
            ->paginate(12, ['*'], 'machinesPage');

        $sales = VendingSale::query()
            ->where('company_id', $companyId)
            ->with(['partner:id,name', 'machine:id,name,code', 'product:id,name'])
            ->when($partnerId, fn ($q) => $q->where('vending_partner_id', $partnerId))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('receipt_number', 'like', "%{$search}%")
                        ->orWhere('mercadopago_payment_id', 'like', "%{$search}%")
                        ->orWhereHas('partner', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('machine', fn ($mq) => $mq->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('sold_at')
            ->paginate(20, ['*'], 'salesPage');

        $today = VendingSale::where('company_id', $companyId)
            ->whereDate('sold_at', today())
            ->whereIn('status', ['approved', 'partially_refunded']);

        $metrics = [
            'active_machines' => VendingMachine::where('company_id', $companyId)->where('status', 'active')->count(),
            'sales_today' => (clone $today)->count(),
            'gross_today' => (float) (clone $today)->sum('gross_amount'),
            'factory_today' => (float) (clone $today)->sum('factory_amount'),
            'pending_factory' => (float) VendingSale::where('company_id', $companyId)
                ->whereNull('settled_at')
                ->whereIn('status', ['approved', 'partially_refunded'])
                ->sum('factory_amount'),
        ];

        $pendingByPartner = VendingSale::query()
            ->where('company_id', $companyId)
            ->whereNull('settled_at')
            ->whereIn('status', ['approved', 'partially_refunded'])
            ->selectRaw('vending_partner_id, COUNT(*) as sales_count, SUM(factory_amount) as factory_total')
            ->groupBy('vending_partner_id')
            ->get()
            ->keyBy('vending_partner_id');

        return view('livewire.vending.dashboard', compact(
            'partners', 'machines', 'sales', 'metrics', 'pendingByPartner'
        ));
    }
}
