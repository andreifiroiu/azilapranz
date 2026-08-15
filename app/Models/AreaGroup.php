<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AreaGroup extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    public function scopeInCity(Builder $query, string $citySlug): void
    {
        $query->where('city_slug', $citySlug);
    }
}
