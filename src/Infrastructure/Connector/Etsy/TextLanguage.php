<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

final class TextLanguage
{
    private const array FRENCH_WORDS = ['le', 'la', 'les', 'des', 'du', 'un', 'une', 'et', 'est', 'en', 'pour', 'avec', 'sur', 'dans', 'au', 'aux', 'ce', 'cette', 'ces', 'vous', 'votre', 'vos', 'sans', 'par', 'qui', 'que', 'pas', 'mais', 'chaque', 'il', 'elle'];
    private const array ENGLISH_WORDS = ['the', 'and', 'of', 'to', 'is', 'in', 'for', 'with', 'your', 'you', 'this', 'that', 'it', 'are', 'be', 'by', 'from', 'without', 'each', 'its', 'will', 'can', 'not', 'about', 'made'];
    private const string FRENCH_LETTERS = '/[àâçéèêëîïôûùüÿœ]/u';

    public function of(string $text): string
    {
        $text = mb_strtolower($text);
        $words = preg_split('/[^\p{L}]+/u', $text, flags: \PREG_SPLIT_NO_EMPTY) ?: [];
        $french = \count(array_intersect($words, self::FRENCH_WORDS)) + (int) preg_match_all(self::FRENCH_LETTERS, $text);
        $english = \count(array_intersect($words, self::ENGLISH_WORDS));

        return $english > $french ? 'en' : 'fr';
    }
}
