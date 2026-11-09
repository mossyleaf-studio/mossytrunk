<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\SumUp;

use App\Application\Integration\Exception\UnreadableCatalogue;
use App\Application\Integration\ExternalLine;
use App\Domain\Shared\Money;

final readonly class SumUpCatalogueReader
{
    private const array HEADERS = [
        'name' => ['itemname', 'name', 'nom', 'nomdelarticle', 'article', 'product', 'produit'],
        'variation' => ['variations', 'variation', 'variant', 'variants', 'variante', 'variantes'],
        'sku' => ['sku', 'reference', 'ref'],
        'category' => ['category', 'categorie', 'categories'],
        'price' => ['price', 'prix'],
    ];
    private const array DELIMITERS = [',', ';', "\t"];

    /**
     * @return list<ExternalLine>
     */
    public function lines(string $file): array
    {
        $rows = $this->rows($file);
        $columns = $this->columns(array_shift($rows) ?? []);

        $categories = [];
        $singles = [];
        $variants = [];
        $current = null;
        foreach ($rows as $row) {
            $cell = static fn (string $field): string => self::cell($row, $columns, $field);
            $name = $cell('name');
            if ('' !== $name) {
                $current = $name;
                $categories[$name] = self::filled($cell('category')) ?? $categories[$name] ?? null;
            }
            if (null === $current) {
                continue;
            }
            $variation = $cell('variation');
            $line = self::line($current, self::filled($cell('category')) ?? $categories[$current] ?? null, $cell('sku'), $cell('price'), self::filled($variation));
            if ('' !== $variation) {
                $variants[$current][$variation] = $line;
            } elseif ('' !== $name) {
                $singles[$current] = $line;
            }
        }

        return self::flattened($singles, $variants);
    }

    /**
     * @param array<string, ExternalLine>                $singles
     * @param array<string, array<string, ExternalLine>> $variants
     *
     * @return list<ExternalLine>
     */
    private static function flattened(array $singles, array $variants): array
    {
        $lines = [];
        foreach ($singles + array_fill_keys(array_keys($variants), null) as $name => $single) {
            $ofItem = array_values($variants[$name] ?? []);
            array_push($lines, ...([] === $ofItem ? array_filter([$single]) : $ofItem));
        }

        return $lines;
    }

    /**
     * @param list<string>       $row
     * @param array<string, int> $columns
     */
    private static function cell(array $row, array $columns, string $field): string
    {
        return isset($columns[$field]) ? trim($row[$columns[$field]] ?? '') : '';
    }

    private static function filled(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $file): array
    {
        $file = preg_replace('/^\x{FEFF}/u', '', $file) ?? $file;
        if (!mb_check_encoding($file, 'UTF-8')) {
            $file = mb_convert_encoding($file, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($file, "\r\n");
        $delimiter = self::DELIMITERS[0];
        foreach (self::DELIMITERS as $candidate) {
            if (substr_count((string) $firstLine, $candidate) > substr_count((string) $firstLine, $delimiter)) {
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new UnreadableCatalogue('SumUp');
        }
        fwrite($stream, $file);
        rewind($stream);
        $rows = [];
        while (false !== ($row = fgetcsv($stream, separator: $delimiter, escape: ''))) {
            $rows[] = array_map(static fn (?string $cell): string => $cell ?? '', $row);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $label) {
            foreach (self::HEADERS as $field => $aliases) {
                if (!isset($columns[$field]) && \in_array(self::normalized($label), $aliases, true)) {
                    $columns[$field] = $index;
                }
            }
        }
        if (!isset($columns['name'])) {
            throw new UnreadableCatalogue('SumUp');
        }

        return $columns;
    }

    private static function line(string $name, ?string $category, string $sku, string $price, ?string $variant): ExternalLine
    {
        return new ExternalLine(mb_strtolower($name), $name, Money::cents(self::cents($price)), 1, $variant, $category, '' === $sku ? null : $sku);
    }

    private static function cents(string $price): int
    {
        $number = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $price) ?? '');

        return is_numeric($number) ? (int) round((float) $number * 100) : 0;
    }

    private static function normalized(string $label): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label);

        return preg_replace('/[^a-z0-9]/', '', false === $ascii ? mb_strtolower($label) : $ascii) ?? '';
    }
}
