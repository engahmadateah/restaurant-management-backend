<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use Auditable, HasFactory, HasTranslations;

    protected $fillable = ['name', 'translations', 'slug', 'is_active', 'sort_order', 'tax_rate'];

    // الحقول القابلة للترجمة عن طريق HasTranslations::translate()
    protected array $translatable = ['name'];

    protected $casts = [
        'is_active' => 'boolean',
        'translations' => 'array',
        'tax_rate' => 'decimal:4',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }
}