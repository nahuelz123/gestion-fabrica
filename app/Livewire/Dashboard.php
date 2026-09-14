<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $companyId = auth()->user()->company_id;

        // Products with zero stock or below min_stock (sum across all warehouses)
        $alerts = Product::where('company_id', $companyId)
            ->whereHas('stocks')
            ->withSum('stocks as total_stock', 'quantity')
            ->get()
            ->filter(function ($product) {
                if ($product->total_stock == 0) return true;
                if ($product->min_stock !== null && $product->total_stock <= $product->min_stock) return true;
                return false;
            });

        return view('livewire.dashboard', compact('alerts'));
    }
}
