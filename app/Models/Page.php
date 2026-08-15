<?php

namespace App\Models;

use App\Support\LegacyHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'is_sitemap' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('published', true);
    }

    public function getPathAttribute(): string
    {
        return '/'.$this->name.'.html';
    }

    /**
     * Legacy CMS markup, rebuilt from a safe allowlist. One page carries dead
     * Twitter/Facebook widget scripts loaded over plain http.
     */
    public function getContentHtmlAttribute(): string
    {
        return LegacyHtml::clean($this->content);
    }
}
