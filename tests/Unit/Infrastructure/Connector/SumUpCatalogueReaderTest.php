<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Connector;

use App\Application\Integration\CatalogueItem;
use App\Application\Integration\Exception\UnreadableCatalogue;
use App\Application\Integration\ExternalLine;
use App\Domain\Shared\Money;
use App\Infrastructure\Connector\SumUp\SumUpCatalogueCsv;
use App\Infrastructure\Connector\SumUp\SumUpCatalogueReader;
use PHPUnit\Framework\TestCase;

final class SumUpCatalogueReaderTest extends TestCase
{
    public function testReadsBackWhatTheExportWrites(): void
    {
        $file = (new SumUpCatalogueCsv())->of([
            new CatalogueItem('Sticker Mousse', 'Sticker', 'STK-001', Money::cents(400), []),
            new CatalogueItem('Print Forêt', 'Print', 'PRT-002', Money::cents(1_250), ['A4', 'A5']),
        ]);

        self::assertSame([
            ['sticker mousse', 'Sticker Mousse', 400, null, 'Sticker', 'STK-001'],
            ['print forêt', 'Print Forêt', 1_250, 'A4', 'Print', 'PRT-002-A4'],
            ['print forêt', 'Print Forêt', 1_250, 'A5', 'Print', 'PRT-002-A5'],
        ], self::described((new SumUpCatalogueReader())->lines($file)));
    }

    public function testToleratesFrenchHeadersSemicolonsRepeatedNamesAndMissingSkus(): void
    {
        $file = "\u{FEFF}Nom de l'article;Variante;Prix;Catégorie;Description\r\n"
            ."Mug Chat;;12,90;Mug;Céramique\r\n"
            ."T-shirt Lune;S;20;Textile;\r\n"
            ."T-shirt Lune;M;20;Textile;\r\n"
            .";;;;\r\n";

        self::assertSame([
            ['mug chat', 'Mug Chat', 1_290, null, 'Mug', null],
            ['t-shirt lune', 'T-shirt Lune', 2_000, 'S', 'Textile', null],
            ['t-shirt lune', 'T-shirt Lune', 2_000, 'M', 'Textile', null],
        ], self::described((new SumUpCatalogueReader())->lines($file)));
    }

    public function testReadsWindowsEncodedFilesAndSkipsVariationsBeforeTheFirstItem(): void
    {
        $file = mb_convert_encoding("Item name,Variations,Price\r\n,Orphan,5\r\nCafé,,3.50\r\n", 'Windows-1252', 'UTF-8');

        self::assertSame([
            ['café', 'Café', 350, null, null, null],
        ], self::described((new SumUpCatalogueReader())->lines($file)));
    }

    public function testAFileWithoutAnItemNameColumnIsRefused(): void
    {
        $this->expectException(UnreadableCatalogue::class);

        (new SumUpCatalogueReader())->lines("Foo,Bar\r\n1,2\r\n");
    }

    /**
     * @param list<ExternalLine> $lines
     *
     * @return list<array{string, string, int, ?string, ?string, ?string}>
     */
    private static function described(array $lines): array
    {
        return array_map(static fn (ExternalLine $line): array => [$line->externalRef, $line->name, $line->unitPrice->amount(), $line->variant, $line->category, $line->sku], $lines);
    }
}
