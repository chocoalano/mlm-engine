<?php

declare(strict_types=1);

namespace PandaPanel\Support\Spreadsheet;

use Generator;
use SimpleXMLElement;
use ZipArchive;

/**
 * The smallest XLSX that Excel, Numbers, and LibreOffice all open.
 *
 * An XLSX is a zip of XML parts, and writing one needs four of them. That is
 * why this exists instead of a spreadsheet library: the library would be a
 * dependency decision, and this is a hundred lines of a format that has not
 * changed since 2007.
 *
 * Strings are written inline rather than through a shared-strings table.
 * A shared table is smaller for repetitive data and is a second pass over
 * everything to build, which is the wrong trade for an export that streams.
 *
 * What it does not do: styles, formulas, multiple sheets, dates as date
 * cells. An export is a table of values, and a value that needs formatting
 * is formatted before it gets here.
 */
final class Xlsx
{
    private const MAX_XML_PART_BYTES = 64 * 1024 * 1024;

    private const CONTENT_TYPES = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
        <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
        <Default Extension="xml" ContentType="application/xml"/>
        <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
        <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
        </Types>
        XML;

    private const ROOT_RELS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
        <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
        </Relationships>
        XML;

    private const WORKBOOK_RELS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
        <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
        </Relationships>
        XML;

    /**
     * Writes a whole sheet.
     *
     * Rows arrive as an iterable so an export can hand over a lazy chunked
     * query rather than an array of everything — the sheet XML is built one
     * row at a time and only the finished string is held.
     *
     * @param  iterable<int, list<string>>  $rows
     */
    public static function write(string $path, iterable $rows, string $sheetName = 'Sheet1'): void
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $number = 0;

        foreach ($rows as $row) {
            $number++;
            $sheet .= '<row r="'.$number.'">';

            foreach ($row as $index => $cell) {
                $reference = self::columnName($index).$number;

                // Everything is a string cell. A number written as an inline
                // string is shown as typed, which is what you want for an
                // order reference or a zero-padded code — the two places a
                // spreadsheet's helpfulness destroys data.
                $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'
                    .self::escape($cell)
                    .'</t></is></c>';
            }

            $sheet .= '</row>';
        }

        $sheet .= '</sheetData></worksheet>';

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SpreadsheetException(sprintf('Cannot write to %s.', $path));
        }

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::ROOT_RELS);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
    }

    /**
     * Reads the first sheet, row by row.
     *
     * @return Generator<int, list<string>>
     */
    public static function read(string $path, int $maxXmlPartBytes = self::MAX_XML_PART_BYTES): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.unreadable'));
        }

        try {
            $shared = self::sharedStrings($zip, $maxXmlPartBytes);
            $sheet = self::firstSheet($zip, $maxXmlPartBytes);

            $xml = @simplexml_load_string($sheet, SimpleXMLElement::class, LIBXML_NONET);

            if ($xml === false) {
                throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.read_failed'));
            }

            foreach ($xml->sheetData->row as $row) {
                yield self::cells($row, $shared);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * One row, with gaps filled.
     *
     * A spreadsheet omits empty cells entirely, so `A1` and `C1` arrive as two
     * cells and the second is *not* the second column. Reading the reference
     * back into a position is what keeps a row with a blank cell from
     * shifting every value after it into the wrong column.
     *
     * @param  array<int, string>  $shared
     * @return list<string>
     */
    private static function cells(SimpleXMLElement $row, array $shared): array
    {
        $cells = [];

        foreach ($row->c as $cell) {
            $reference = (string) ($cell['r'] ?? '');
            $index = $reference === '' ? count($cells) : self::columnIndex($reference);
            $type = (string) ($cell['t'] ?? '');

            $value = match ($type) {
                's' => $shared[(int) $cell->v] ?? '',
                'inlineStr' => (string) $cell->is->t,
                default => (string) $cell->v,
            };

            $cells[$index] = $value;
        }

        if ($cells === []) {
            return [];
        }

        // Filled to the last cell that had a value, so positions line up with
        // the header row.
        $filled = [];

        for ($index = 0; $index <= max(array_keys($cells)); $index++) {
            $filled[] = $cells[$index] ?? '';
        }

        return $filled;
    }

    /**
     * @return array<int, string>
     */
    private static function sharedStrings(ZipArchive $zip, int $maxBytes): array
    {
        $contents = self::part($zip, 'xl/sharedStrings.xml', $maxBytes, required: false);

        if ($contents === null) {
            return [];
        }

        $xml = @simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach ($xml->si as $item) {
            // A string with mixed formatting is split across `<r>` runs, and
            // the value is all of them joined — taking only `<t>` would drop
            // every styled word.
            $strings[] = $item->t->count() > 0
                ? (string) $item->t
                : implode('', array_map(strval(...), iterator_to_array($item->r->t ?? [], false)));
        }

        return $strings;
    }

    private static function firstSheet(ZipArchive $zip, int $maxBytes): string
    {
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml'] as $name) {
            $contents = self::part($zip, $name, $maxBytes, required: false);

            if ($contents !== null) {
                return $contents;
            }
        }

        throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.no_sheet'));
    }

    private static function part(ZipArchive $zip, string $name, int $maxBytes, bool $required): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            if ($required) {
                throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.no_sheet'));
            }

            return null;
        }

        $size = $stat['size'];

        if ($size > $maxBytes) {
            throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.too_large'));
        }

        $contents = $zip->getFromName($name);

        if ($contents === false) {
            if ($required) {
                throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.no_sheet'));
            }

            return null;
        }

        if (strlen($contents) > $maxBytes) {
            throw new SpreadsheetException(__('panda-panel::errors.spreadsheet.too_large'));
        }

        return $contents;
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /**
     * 0 becomes A, 26 becomes AA — bijective base-26, which is what a
     * spreadsheet column is.
     */
    private static function columnName(int $index): string
    {
        $name = '';

        for ($position = $index + 1; $position > 0; $position = intdiv($position - 1, 26)) {
            $name = chr(65 + ($position - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * The reverse: `C7` is column 2, counting from zero.
     */
    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', $reference, $matches);

        $letters = $matches[1] ?? 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function escape(string $value): string
    {
        // Control characters are not legal in XML at all, and a stray one in
        // a database column would make the whole file unopenable.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
