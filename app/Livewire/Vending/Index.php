<?php

namespace App\Livewire\Vending;

use App\Models\VendingMachine;
use App\Models\VendingSale;
use App\Services\MercadoPagoVendingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('owner-only');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function provision(int $machineId, MercadoPagoVendingService $service): void
    {
        Gate::authorize('owner-only');
        $machine = VendingMachine::where('company_id', auth()->user()->company_id)
            ->with(['partner', 'product'])
            ->findOrFail($machineId);

        if ($machine->loaded_units <= 0) {
            session()->flash('error', 'Cargá stock en la máquina antes de preparar un cobro.');
            return;
        }

        try {
            $service->provisionMachine($machine, true);
            session()->flash('message', "{$machine->name} quedó sincronizada con Mercado Pago.");
        } catch (Throwable $e) {
            report($e);
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $todaySales = VendingSale::where('company_id', $companyId)
            ->whereIn('status', ['approved', 'partially_refunded'])
            ->whereDate('sold_at', now()->toDateString());

        $machines = VendingMachine::where('company_id', $companyId)
            ->with(['partner', 'product'])
            ->when($this->search, fn ($q) => $q->where(function ($sq) {
                $term = '%' . $this->search . '%';
                $sq->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhereHas('partner', fn ($pq) => $pq->where('name', 'like', $term))
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $term));
            }))
            ->orderBy('name')
            ->paginate(25);

        $gross = (float) (clone $todaySales)->sum('gross_amount');
        $refunded = (float) (clone $todaySales)->sum('refunded_amount');

        $stats = [
            'sales_count' => (clone $todaySales)->count(),
            'gross' => max(0, $gross - $refunded),
            'factory' => (float) (clone $todaySales)->sum('factory_amount'),
            'commission' => (float) (clone $todaySales)->sum('commission_amount'),
            'pending_settlement' => (float) VendingSale::where('company_id', $companyId)
                ->whereIn('status', ['approved', 'partially_refunded'])
                ->whereNull('settled_at')->sum('factory_amount'),
        ];

        return view('livewire.vending.index', compact('machines', 'stats'));
    }
}
