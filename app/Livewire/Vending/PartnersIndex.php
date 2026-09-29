<?php

namespace App\Livewire\Vending;

use App\Models\VendingPartner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PartnersIndex extends Component
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

    public function render()
    {
        $companyId = auth()->user()->company_id;
        $partners = VendingPartner::where('company_id', $companyId)
            ->withCount('machines')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.vending.partners-index', compact('partners'));
    }
}
