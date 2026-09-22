<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BranchPaymentMethod extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'is_enabled' => 'boolean',
            'show_on_storefront' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function label(): string
    {
        return $this->label_override ?: $this->method->label();
    }
}
