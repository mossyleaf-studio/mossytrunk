<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Infrastructure\Http\Json;

final class EtsyDescriptions
{
    private const array LANGUAGES = ['fr', 'en'];

    /**
     * @param array<string, mixed> $listing
     *
     * @return array<string, string>
     */
    public function of(array $listing): array
    {
        $descriptions = self::described($listing['language'] ?? null, $listing['description'] ?? null);
        foreach (\is_array($listing['translations'] ?? null) ? $listing['translations'] : [] as $tag => $translation) {
            $translation = Json::object($translation);
            $descriptions += self::described($translation['language'] ?? $tag, $translation['description'] ?? null);
        }

        return $descriptions;
    }

    /**
     * @return array<string, string>
     */
    private static function described(mixed $tag, mixed $description): array
    {
        $language = strtolower(preg_split('/[-_]/', trim(Json::string($tag)))[0] ?? '');
        $text = \is_string($description) ? self::plain($description) : '';

        return \in_array($language, self::LANGUAGES, true) && '' !== $text ? [$language => $text] : [];
    }

    private static function plain(string $text): string
    {
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string) preg_replace('~</?[a-z][^>]*>~i', '', $text);

        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
