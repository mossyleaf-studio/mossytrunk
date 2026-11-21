<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\EtsyDescriptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EtsyDescriptionsTest extends TestCase
{
    public function testTheTextIsKeyedByTheLanguageEtsyDeclaresAndATranslationFillsTheOtherOne(): void
    {
        $descriptions = (new EtsyDescriptions())->of([
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

    public function testATextInAnotherLanguageThanFrenchOrEnglishIsDropped(): void
    {
        $descriptions = (new EtsyDescriptions())->of([
            'language' => 'de',
            'description' => 'Matter Vinylaufkleber.',
            'translations' => ['en' => ['language' => 'en', 'description' => 'Matte vinyl sticker.'], 'es' => ['language' => 'es', 'description' => 'Pegatina de vinilo.']],
        ]);

        self::assertSame(['en' => 'Matte vinyl sticker.'], $descriptions);
    }

    public function testEntitiesAreDecodedLineBreaksKeptAndHtmlNeverKept(): void
    {
        $descriptions = (new EtsyDescriptions())->of([
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
        self::assertSame([], (new EtsyDescriptions())->of($listing));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function listingsWithoutText(): iterable
    {
        yield 'no description' => [['language' => 'fr']];
        yield 'a blank description' => [['language' => 'fr', 'description' => " \n <br> "]];
        yield 'no language declared' => [['language' => null, 'description' => 'Sticker en vinyle mat.']];
        yield 'a description that is not a text' => [['language' => 'fr', 'description' => ['Sticker']]];
        yield 'translations that are not a map' => [['translations' => 'none']];
        yield 'a translation that is not an object' => [['translations' => ['en' => 'Matte vinyl sticker.']]];
    }
}
