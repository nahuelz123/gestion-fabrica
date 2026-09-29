<?php

namespace App\Livewire\Inventory;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class StockMovements extends Component
{
    use WithPagination;
    public string $search = '';
    public string $type = '';
    public string $channel = '';
    public string $warehouse_id = '';
    public string $date_from = '';
    public string $date_to = '';
    public int $perPage = 50;

    public function mount(): void { Gate::authorize('manage-stock'); }
    public function updated($property): void
    {
        if (in_array($property, ['search','type','channel','warehouse_id','date_from','date_to','perPage'], true)) $this->resetPage();
    }
    public function render()
    {
        Gate::authorize('manage-stock');
        $companyId = auth()->user()->company_id;
        $search = trim($this->search);
        $movements = StockMovement::query()->where('company_id', $companyId)
            ->with(['product:id,name,internal_code,base_unit_id','product.baseUnit:id,abbreviation','warehouse:id,name','lot:id,lot_code','presentation:id,name','user:id,name'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('reason', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($pq) use ($search) {
                            $pq->where('name', 'like', "%{$search}%")->orWhere('internal_code', 'like', "{$search}%")
                                ->orWhereHas('aliases', fn ($aq) => $aq->where('alias', 'like', "%{$search}%"));
                        });
                });
            })
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->warehouse_id !== '', fn ($q) => $q->where('warehouse_id', $this->warehouse_id))
            ->when($this->date_from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->date_from))
            ->when($this->date_to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->date_to))
            ->latest('created_at')->paginate(max(10, min($this->perPage, 100)));
        return view('livewire.inventory.stock-movements', [
            'movements' => $movements,
            'warehouses' => Warehouse::where('company_id', $companyId)->orderBy('name')->get(['id','name']),
            'types' => MovementType::cases(), 'channels' => Channel::cases(),
        ]);
    }
}
