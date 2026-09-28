<?php

declare(strict_types=1);

namespace PandaPanel\Support\Spreadsheet;

use RuntimeException;

/**
 * A file that could not be written or could not be understood.
 *
 * Its own type so an import can tell "this file is not a spreadsheet" apart
 * from "this row is invalid": the first is a failed upload the user should be
 * told about once, the second is a row to collect and hand back.
 */
final class SpreadsheetException extends RuntimeException {}
