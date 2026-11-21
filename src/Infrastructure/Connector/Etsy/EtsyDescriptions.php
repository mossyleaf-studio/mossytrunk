<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Infrastructure\Http\Json;

final readonly class EtsyDescriptions
{
    private const array LANGUAGES = ['fr', 'en'];

    public function __construct(private TextLanguage $language)
    {
    }

    /**
     * @param array<string, mixed> $listing
     *
     * @return array<string, string>
     */
    public function of(array $listing): array
    {
        $descriptions = $this->own($listing);
        foreach (\is_array($listing['translations'] ?? null) ? $listing['translations'] : [] as $tag => $translation) {
            $translation = Json::object($translation);
            $descriptions += self::described(self::language($translation['language'] ?? $tag), self::text($translation['description'] ?? null));
        }

        return $descriptions;
    }

    /**
     * @param array<string, mixed> $listing
     *
     * @return array<string, string>
     */
    private function own(array $listing): array
    {
        $text = self::text($listing['description'] ?? null);
        $declared = self::language($listing['language'] ?? null);

        return self::described(\in_array($declared, self::LANGUAGES, true) || '' === $text ? $declared : $this->language->of($text), $text);
    }

    /**
     * @return array<string, string>
     */
    private static function described(string $language, string $text): array
    {
        return \in_array($language, self::LANGUAGES, true) && '' !== $text ? [$language => $text] : [];
    }

    private static function language(mixed $tag): string
    {
        return strtolower(preg_split('/[-_]/', trim(Json::string($tag)))[0] ?? '');
    }

    private static function text(mixed $description): string
    {
        return \is_string($description) ? self::plain($description) : '';
    }

    private static function plain(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string) preg_replace('~</?[a-z][^>]*>~i', '', $text);

        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
