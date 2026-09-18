<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class BillingEvent extends Model
{
    protected $table = 'billing_events';

    protected $fillable = ['event_id', 'type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
