<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Imports\Importer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands back the rows an import could not accept.
 *
 * The same rule the export download follows: the request names a file, the
 * directory is built from whoever is asking, and the only files reachable are
 * this user's own. See `PanelExportController` for why that matters — a
 * failure report is a copy of the data somebody tried to import, which is
 * every bit as worth protecting as the export.
 */
final class PanelImportController
{
    public function __invoke(Request $request, string $file): StreamedResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        // A name, never a path.
        abort_if(
            $file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..'),
            404,
        );

        $importer = $request->query('importer');

        abort_unless(
            is_string($importer) && is_subclass_of($importer, Importer::class),
            404,
            __('panda-panel::errors.unknown_import'),
        );

        $disk = Storage::disk($importer::disk());
        $path = $importer::directory().'/'.$user->getAuthIdentifier().'/'.$file;

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $file);
    }
}
