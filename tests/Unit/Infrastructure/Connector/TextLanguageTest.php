<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Infrastructure\Connector\Etsy\TextLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextLanguageTest extends TestCase
{
    #[DataProvider('texts')]
    public function testTheLanguageIsTheOneItsCommonWordsAndLettersShow(string $text, string $language): void
    {
        self::assertSame($language, (new TextLanguage())->of($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'french with accents' => ["Sticker en vinyle mat, résistant à l'eau.\nEnviron 7 cm.", 'fr'];
        yield 'french without accents' => ['Tirage sur papier mat pour la maison, livre sans cadre et avec une pochette.', 'fr'];
        yield 'a long french description' => ["Ce sticker est découpé à la main dans mon atelier. Il tient sur vos carnets, vos gourdes et votre ordinateur.\n\nTaille : 7 cm.", 'fr'];
        yield 'english' => ['Matte vinyl sticker, water resistant. About 7 cm.', 'en'];
        yield 'english with a french word' => ['Handmade in the studio of Mossyleaf: a café sticker for your laptop, with a matte finish.', 'en'];
        yield 'a long english description' => ["This print of the forest is made on matte paper.\n\nIt is shipped without a frame, in a rigid envelope.", 'en'];
        yield 'a text that does not tell' => ['Mousse — 7 × 10 cm', 'fr'];
        yield 'a text in neither language' => ['Matter Vinylaufkleber.', 'fr'];
    }
}
