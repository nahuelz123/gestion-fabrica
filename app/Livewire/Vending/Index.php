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
        $validStatuses = ['approved', 'partially_refunded'];

        $sales = VendingSale::where('company_id', $companyId)
            ->whereIn('status', $validStatuses);

        $stats = [
            'today' => (clone $sales)->whereDate('sold_at', now()->toDateString())->count(),
            'week' => (clone $sales)->where('sold_at', '>=', now()->startOfWeek())->count(),
            'month' => (clone $sales)->where('sold_at', '>=', now()->startOfMonth())->count(),
            'machines' => VendingMachine::where('company_id', $companyId)->where('status', 'active')->count(),
        ];

        $machines = VendingMachine::where('company_id', $companyId)
            ->with(['partner', 'product'])
            ->withCount([
                'sales as sales_today_count' => fn ($q) => $q
                    ->whereIn('status', $validStatuses)
                    ->whereDate('sold_at', now()->toDateString()),
                'sales as sales_month_count' => fn ($q) => $q
                    ->whereIn('status', $validStatuses)
                    ->where('sold_at', '>=', now()->startOfMonth()),
            ])
            ->when($this->search, fn ($q) => $q->where(function ($sq) {
                $term = '%' . $this->search . '%';
                $sq->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhereHas('partner', fn ($pq) => $pq->where('name', 'like', $term))
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $term));
            }))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.vending.index', compact('machines', 'stats'));
    }
}
