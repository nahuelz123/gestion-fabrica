<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'password', 'role', 'status', 'telegram_chat_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function isOwner(): bool { return $this->role === UserRole::Owner; }
    public function isManager(): bool { return $this->role === UserRole::Manager; }
    public function isActive(): bool { return $this->status === UserStatus::Active; }
    public function canManageStock(): bool { return $this->isOwner() || $this->isManager(); }
    public function canManageProduction(): bool { return $this->isOwner() || $this->isManager(); }
    public function canSimulateProduction(): bool { return $this->canManageProduction(); }

    public function canUseBotAction(?string $action): bool
    {
        if ($this->isOwner()) return true;
        if (!$this->isManager() || !$action) return false;

        return in_array($action, [
            // El encargado lleva inventario y operación diaria de producción.
            'get_stock', 'get_low_stock', 'get_expiring_products', 'get_stock_movements',
            'register_stock', 'adjust_stock', 'set_stock', 'add_stock', 'remove_stock',
            'calculate_production', 'check_production', 'get_max_production',
            'get_missing_inputs', 'plan_production', 'register_production',

            // Productos, recetas, compras, proveedores, usuarios y máquinas
            // siguen siendo tareas administrativas de los dueños.
        ], true);
    }

    public function aiConversation() { return $this->hasOne(AiConversation::class); }
}
