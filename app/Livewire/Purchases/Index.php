<?php

namespace App\Livewire\Purchases;

use App\Models\Purchase;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $search = '';

    public function render()
    {
        $purchases = Purchase::with(['supplier', 'user', 'warehouse'])
            ->where('company_id', auth()->user()->company_id)
            ->when($this->search, function ($q) {
                $q->whereHas('supplier', function ($sq) {
                    $sq->where('name', 'like', "%{$this->search}%");
                })
                ->orWhere('invoice_number', 'like', "%{$this->search}%")
                ->orWhere('id', 'like', "%{$this->search}%");
            })
            ->orderBy('id', 'desc')
            ->get();

        return view('livewire.purchases.index', [
            'purchases' => $purchases
        ]);
    }
}
