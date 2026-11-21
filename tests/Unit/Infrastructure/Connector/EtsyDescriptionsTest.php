<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\EtsyDescriptions;
use App\Infrastructure\Connector\Etsy\TextLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EtsyDescriptionsTest extends TestCase
{
    public function testTheTextIsKeyedByTheLanguageEtsyDeclaresAndATranslationFillsTheOtherOne(): void
    {
        $descriptions = self::descriptions()->of([
            'language' => 'fr',
            'description' => 'Sticker en vinyle mat.',
            'translations' => [
                'de' => ['listing_id' => 1, 'language' => 'de', 'title' => 'Aufkleber', 'description' => 'Matter Vinylaufkleber.'],
                'en-GB' => null,
                'en-US' => ['listing_id' => 1, 'language' => 'en-US', 'title' => 'Sticker', 'description' => 'Matte vinyl sticker.'],
                'fr' => ['listing_id' => 1, 'language' => 'fr', 'title' => 'Sticker', 'description' => 'Une autre traduction.'],
            ],
        ]);

        self::assertSame(['fr' => 'Sticker en vinyle mat.', 'en' => 'Matte vinyl sticker.'], $descriptions);
    }

    public function testATranslationInAnotherLanguageThanFrenchOrEnglishIsDropped(): void
    {
        $descriptions = self::descriptions()->of([
            'language' => 'fr',
            'description' => 'Sticker en vinyle mat.',
            'translations' => ['de' => ['language' => 'de', 'description' => 'Matter Vinylaufkleber.'], 'es' => ['language' => 'es', 'description' => 'Pegatina de vinilo.']],
        ]);

        self::assertSame(['fr' => 'Sticker en vinyle mat.'], $descriptions);
    }

    /**
     * @param array<string, mixed>  $listing
     * @param array<string, string> $expected
     */
    #[DataProvider('listingsWithoutAUsableLanguage')]
    public function testTheListingsOwnTextIsKeptUnderTheLanguageItShowsWhenEtsyDeclaresNoneOrAnother(array $listing, array $expected): void
    {
        self::assertSame($expected, self::descriptions()->of($listing));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function listingsWithoutAUsableLanguage(): iterable
    {
        yield 'no language' => [['description' => 'Sticker en vinyle mat, résistant à l&#39;eau.'], ['fr' => "Sticker en vinyle mat, résistant à l'eau."]];
        yield 'language null' => [['language' => null, 'description' => 'Matte vinyl sticker, water resistant. About 7 cm.'], ['en' => 'Matte vinyl sticker, water resistant. About 7 cm.']];
        yield 'another language' => [['language' => 'de', 'description' => 'The print of the forest, on matte paper.'], ['en' => 'The print of the forest, on matte paper.']];
        yield 'a language that is not a text' => [['language' => 7, 'description' => 'Affiche de la forêt.'], ['fr' => 'Affiche de la forêt.']];
        yield 'a text that does not tell' => [['language' => 'de', 'description' => 'Matter Vinylaufkleber.'], ['fr' => 'Matter Vinylaufkleber.']];
        yield 'a translation fills the other language' => [
            ['language' => '', 'description' => 'Matte vinyl sticker, water resistant. About 7 cm.', 'translations' => ['fr' => ['language' => 'fr', 'description' => 'Sticker en vinyle mat.']]],
            ['en' => 'Matte vinyl sticker, water resistant. About 7 cm.', 'fr' => 'Sticker en vinyle mat.'],
        ];
    }

    public function testEntitiesAreDecodedLineBreaksKeptAndHtmlNeverKept(): void
    {
        $descriptions = self::descriptions()->of([
            'language' => 'en-US',
            'description' => "  <p>L&#39;atelier &amp; the &quot;moss&quot;</p><br/>I &lt;3 it\r\nTaille&nbsp;: 7&nbsp;cm &lt;b&gt;bold&lt;/b&gt;  ",
        ]);

        self::assertSame(['en' => "L'atelier & the \"moss\"\nI <3 it\nTaille\u{a0}: 7\u{a0}cm bold"], $descriptions);
    }

    /**
     * @param array<string, mixed> $listing
     */
    #[DataProvider('listingsWithoutText')]
    public function testAListingWithoutAReadableTextHasNoDescription(array $listing): void
    {
        self::assertSame([], self::descriptions()->of($listing));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function listingsWithoutText(): iterable
    {
        yield 'no description' => [['language' => 'fr']];
        yield 'a blank description' => [['language' => 'fr', 'description' => " \n <br> "]];
        yield 'a description that is not a text' => [['language' => 'fr', 'description' => ['Sticker']]];
        yield 'translations that are not a map' => [['translations' => 'none']];
        yield 'a translation that is not an object' => [['translations' => ['en' => 'Matte vinyl sticker.']]];
    }

    private static function descriptions(): EtsyDescriptions
    {
        return new EtsyDescriptions(new TextLanguage());
    }
}
