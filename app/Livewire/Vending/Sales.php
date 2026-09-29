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
    public string $settlement = 'all';

    public function mount(): void
    {
        Gate::authorize('owner-only');
        $this->date_from = now()->startOfMonth()->toDateString();
        $this->date_to = now()->toDateString();
    }

    public function updated($property): void
    {
        if (in_array($property, ['date_from', 'date_to', 'partner_id', 'machine_id', 'settlement'], true)) {
            $this->resetPage();
        }
    }

    public function markSettled(): void
    {
        Gate::authorize('owner-only');
        $this->validate([
            'partner_id' => 'required|integer',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ], [], ['partner_id' => 'comercio', 'date_from' => 'desde', 'date_to' => 'hasta']);

        $companyId = auth()->user()->company_id;
        $partner = VendingPartner::where('company_id', $companyId)->findOrFail((int) $this->partner_id);

        $query = VendingSale::where('company_id', $companyId)
            ->where('vending_partner_id', $partner->id)
            ->whereIn('status', ['approved', 'partially_refunded'])
            ->whereNull('settled_at')
            ->whereDate('sold_at', '>=', $this->date_from)
            ->whereDate('sold_at', '<=', $this->date_to);

        $count = $query->count();
        if ($count === 0) {
            session()->flash('error', 'No hay ventas pendientes para liquidar en ese período.');
            return;
        }

        $query->update([
            'settled_at' => now(),
            'settled_by_user_id' => auth()->id(),
            'updated_at' => now(),
        ]);

        session()->flash('message', "Se marcaron {$count} ventas de {$partner->name} como liquidadas.");
    }

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $base = VendingSale::where('company_id', $companyId)
            ->with(['partner', 'machine', 'product'])
            ->when($this->date_from, fn ($q) => $q->whereDate('sold_at', '>=', $this->date_from))
            ->when($this->date_to, fn ($q) => $q->whereDate('sold_at', '<=', $this->date_to))
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->when($this->machine_id, fn ($q) => $q->where('vending_machine_id', $this->machine_id))
            ->when($this->settlement === 'pending', fn ($q) => $q->whereNull('settled_at')->whereIn('status', ['approved', 'partially_refunded']))
            ->when($this->settlement === 'settled', fn ($q) => $q->whereNotNull('settled_at'));

        $summaryQuery = clone $base;
        $summary = [
            'count' => (clone $summaryQuery)->count(),
            'gross' => (float) (clone $summaryQuery)->sum('gross_amount'),
            'refunded' => (float) (clone $summaryQuery)->sum('refunded_amount'),
            'commission' => (float) (clone $summaryQuery)->sum('commission_amount'),
            'factory' => (float) (clone $summaryQuery)->sum('factory_amount'),
        ];

        $sales = $base->latest('sold_at')->paginate(50);
        $partners = VendingPartner::where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);
        $machines = VendingMachine::where('company_id', $companyId)
            ->when($this->partner_id, fn ($q) => $q->where('vending_partner_id', $this->partner_id))
            ->orderBy('name')->get(['id', 'name']);

        return view('livewire.vending.sales', compact('sales', 'partners', 'machines', 'summary'));
    }
}
