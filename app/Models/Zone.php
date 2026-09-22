<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use BelongsToBranch, HasFactory;

    protected $guarded = [];

    public function diningTables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }
}
