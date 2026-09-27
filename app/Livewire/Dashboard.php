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

        // Filtramos en SQL y limitamos el resultado para no cargar miles de
        // productos en memoria cuando el catálogo crezca.
        $alerts = Product::query()
            ->where('company_id', $companyId)
            ->with('baseUnit:id,abbreviation')
            ->withSum('stocks as total_stock', 'quantity')
            ->havingRaw('COALESCE(total_stock, 0) <= 0 OR (min_stock IS NOT NULL AND min_stock > 0 AND COALESCE(total_stock, 0) <= min_stock)')
            ->orderByRaw('COALESCE(total_stock, 0) ASC')
            ->orderBy('name')
            ->limit(50)
            ->get();

        return view('livewire.dashboard', compact('alerts'));
    }
}
