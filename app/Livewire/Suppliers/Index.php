<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public function toggleStatus(int $id)
    {
        $supplier = Supplier::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $supplier->update([
            'status' => $supplier->status === 'active' ? 'inactive' : 'active'
        ]);
    }

    public function render()
    {
        $suppliers = Supplier::where('company_id', auth()->user()->company_id)
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('tax_id', 'like', "%{$this->search}%");
            })
            ->orderBy('name')
            ->get();

        return view('livewire.suppliers.index', [
            'suppliers' => $suppliers
        ]);
    }
}
