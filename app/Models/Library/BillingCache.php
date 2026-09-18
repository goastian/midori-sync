<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class BillingCache extends Model
{
    protected $table = 'billing_cache';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'plan', 'status', 'limits', 'synced_at'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'synced_at' => 'datetime'];
    }
}
