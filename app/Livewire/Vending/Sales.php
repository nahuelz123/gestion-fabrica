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
        $companyId = auth()->user()->company_id;
        $base = VendingSale::where('company_id', $companyId)
            ->with(['partner', 'machine', 'product'])
            ->when($this->date_from, fn ($q) => $q->whereDate('sold_at', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('sold_at', '<=', $this->date_to))
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->when($this->machine_id, fn ($q) => $q->where('vending_machine_id', $this->machine_id));

        $validSales = (clone $base)->whereIn('status', ['approved', 'partially_refunded']);
        $summary = [
            'sales' => (clone $validSales)->count(),
            'partners' => (clone $validSales)->distinct('vending_partner_id')->count('vending_partner_id'),
            'machines' => (clone $validSales)->distinct('vending_machine_id')->count('vending_machine_id'),
            'amount' => max(0, (float) (clone $validSales)->sum('gross_amount') - (float) (clone $validSales)->sum('refunded_amount')),
        ];

        $sales = $base->latest('sold_at')->paginate(50);
        $partners = VendingPartner::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);
        $machines = VendingMachine::where('company_id', $companyId)
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->orderBy('name')->get(['id', 'name']);

        return view('livewire.vending.sales', compact('sales', 'partners', 'machines', 'summary'));
    }
}
