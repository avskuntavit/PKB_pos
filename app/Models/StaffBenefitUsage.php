<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasBusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffBenefitUsage extends Model
{
    use BelongsToBranch, HasBusinessDate, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'order_total' => 'decimal:2',
            'business_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
