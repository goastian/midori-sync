<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sync collection type (bookmarks, history, tabs, passwords, etc.).
 */
class Collection extends Model
{
    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
