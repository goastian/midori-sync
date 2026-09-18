<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class LibraryImportJob extends Model
{
    protected $table = 'library_import_jobs';

    protected $fillable = ['user_id', 'source', 'status', 'total', 'processed', 'errors', 'file_path'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'total' => 'integer', 'processed' => 'integer'];
    }
}
