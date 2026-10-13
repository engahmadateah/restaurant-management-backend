<?php

namespace App\Traits;


trait HasTranslations
{

    public function translate(string $field, ?string $locale = null): ?string
    {
        if (! $locale || $locale === $this->defaultLocale()) {
            return $this->getAttribute($field);
        }

        $value = $this->translations[$locale][$field] ?? null;

        return filled($value) ? $value : $this->getAttribute($field);
    }

  
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
