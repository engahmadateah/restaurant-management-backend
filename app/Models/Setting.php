<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use Auditable;

    /** مفتاح الكاش: كل الإعدادات بمفتاح واحد (query وحدة بدل query لكل إعداد) */
    public const CACHE_KEY = 'settings.all';

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // أي تعديل/حذف (من الـ API أو السيدر أو tinker) بيمسح الكاش، فما بيصير فيه قيمة قديمة
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /** كل الإعدادات key => value (من الكاش) */
    public static function allCached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    /**
     * قراءة إعداد واحد. نفس سلوك القديم: غير موجود (أو null) = القيمة الافتراضية،
     * والنص الفاضي بيرجع كما هو (مثل رقم الهاتف الفاضي).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allCached()[$key] ?? $default;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
