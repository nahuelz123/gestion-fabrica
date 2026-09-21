<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Form extends Component
{
    public ?Supplier $supplier = null;
    
    public string $name = '';
    public string $tax_id = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $status = 'active';

    public function mount($id = null)
    {
        Gate::authorize('owner-only');

        if ($id) {
            $this->supplier = Supplier::where('company_id', auth()->user()->company_id)->findOrFail($id);
            $this->name = $this->supplier->name;
            $this->tax_id = $this->supplier->tax_id ?? '';
            $this->email = $this->supplier->email ?? '';
            $this->phone = $this->supplier->phone ?? '';
            $this->address = $this->supplier->address ?? '';
            $this->status = $this->supplier->status;
        }
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $data = [
            'name' => $this->name,
            'tax_id' => $this->tax_id ?: null,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'status' => $this->status,
        ];

        if ($this->supplier) {
            $this->supplier->update($data);
            $msg = 'Proveedor actualizado.';
        } else {
            $data['company_id'] = auth()->user()->company_id;
            Supplier::create($data);
            $msg = 'Proveedor creado.';
        }

        session()->flash('message', $msg);
        return $this->redirect(route('suppliers.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.suppliers.form');
    }
}
