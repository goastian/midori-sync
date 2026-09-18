<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class LibraryShare extends Model
{
    protected $table = 'library_shares';

    protected $fillable = ['library_link_id', 'token', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
