<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Enums;

/**
 * The two file formats an import reads and an export writes.
 *
 * Closed, because each case maps to a reader and a writer this application
 * ships. A format nobody wrote a reader for is not a format the panel can
 * offer, and saying so in the type is better than discovering it at runtime.
 */
enum SpreadsheetFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel (XLSX)',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    /**
     * What the browser may offer in the file picker, and what the upload is
     * validated against.
     *
     * A spreadsheet arrives under half a dozen media types depending on the
     * program that wrote it, which is why this is a list rather than one
     * string.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Csv => ['text/csv', 'text/plain', 'application/csv'],
            self::Xlsx => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
        };
    }

    /**
     * The format a file claims to be, from its extension.
     *
     * The extension is a claim, not proof, which is why the reader still has
     * to cope with a file that is not what it says.
     */
    public static function fromPath(string $path): self
    {
        return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx'
            ? self::Xlsx
            : self::Csv;
    }
}
