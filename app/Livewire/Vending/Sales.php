<?php

namespace App\Livewire\Vending;

use App\Models\VendingMachine;
use App\Models\VendingPartner;
use App\Models\VendingSale;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Sales extends Component
{
    use WithPagination;

    public string $date_from = '';
    public string $date_to = '';
    public string $partner_id = '';
    public string $machine_id = '';

    public function mount(): void
    {
        Gate::authorize('owner-only');
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->toDateString();
    }

    public function updated($property): void
    {
        if (in_array($property, ['date_from', 'date_to', 'partner_id', 'machine_id'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        Gate::authorize('owner-only');
        $companyId = auth()->user()->company_id;
        $base = VendingSale::where('company_id', $companyId)
            ->with(['partner', 'machine', 'product'])
            ->when($this->date_from, fn ($q) => $q->where('sold_at', '>=', $this->date_from . ' 00:00:00'))
            ->when($this->date_to, fn ($q) => $q->where('sold_at', '<', \Carbon\Carbon::parse($this->date_to)->addDay()->toDateString() . ' 00:00:00'))
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->when($this->machine_id, fn ($q) => $q->where('vending_machine_id', $this->machine_id));

        // Single aggregate query rather than five round trips for every page refresh.
        $totals = (clone $base)->withoutEagerLoads()
            ->whereIn('status', ['approved', 'partially_refunded'])
            ->selectRaw('COUNT(*) AS sales_count')
            ->selectRaw('COUNT(DISTINCT vending_partner_id) AS partners_count')
            ->selectRaw('COUNT(DISTINCT vending_machine_id) AS machines_count')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_total')
            ->selectRaw('COALESCE(SUM(refunded_amount), 0) AS refunded_total')
            ->first();
        $summary = [
            'sales' => (int) $totals->sales_count,
            'partners' => (int) $totals->partners_count,
            'machines' => (int) $totals->machines_count,
            'amount' => max(0, (float) $totals->gross_total - (float) $totals->refunded_total),
        ];

        $sales = $base->latest('sold_at')->paginate(50);
        $partners = VendingPartner::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);
        $machines = VendingMachine::where('company_id', $companyId)
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->orderBy('name')->get(['id', 'name']);

        return view('livewire.vending.sales', compact('sales', 'partners', 'machines', 'summary'));
    }
}
