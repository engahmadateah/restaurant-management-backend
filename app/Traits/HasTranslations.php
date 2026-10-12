<?php

namespace App\Traits;

/**
 * دعم تعدد اللغات لموديل معيّن (مثلاً Category أو MenuItem).
 * الحقول الأصلية (name/description) بتضل هي اللغة الافتراضية (عربي).
 * عمود translations (JSON) بيخزّن باقي اللغات بالشكل:
 *   { "en": { "name": "Hummus", "description": "..." }, "fr": {...} }
 *
 * استخدام بالموديل:
 *   use HasTranslations;
 *   protected array $translatable = ['name', 'description'];
 */
trait HasTranslations
{
    /**
     * بيرجع قيمة حقل معيّن بلغة معيّنة. إذا اللغة المطلوبة مو موجودة
     * أو الحقل فاضي فيها، بيرجع القيمة الافتراضية (عربي) كـ fallback
     * حتى ما توصل قيمة فاضية للواجهة.
     */
    public function translate(string $field, ?string $locale = null): ?string
    {
        if (! $locale || $locale === $this->defaultLocale()) {
            return $this->getAttribute($field);
        }

        $value = $this->translations[$locale][$field] ?? null;

        return filled($value) ? $value : $this->getAttribute($field);
    }

    /**
     * بيرجع نسخة من كل الحقول القابلة للترجمة بلغة معيّنة، جاهزة
     * لدمجها بالـ response (مثال: $item->translatedAttributes('en')).
     */
    public function translatedAttributes(?string $locale = null): array
    {
        $result = [];
        foreach ($this->translatable ?? [] as $field) {
            $result[$field] = $this->translate($field, $locale);
        }

        return $result;
    }

    protected function defaultLocale(): string
    {
        return 'ar';
    }
}
