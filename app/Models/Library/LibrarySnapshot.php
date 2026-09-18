<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class LibrarySnapshot extends Model
{
    protected $table = 'library_snapshots';

    protected $fillable = ['library_link_id', 'kind', 'storage_path', 'mime', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
