<?php

declare(strict_types=1);

namespace PandaPanel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Exports\Exporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands back an export file.
 *
 * The request names a file and nothing else. Which directory it is looked for
 * in is built from the authenticated user, so the only files reachable here
 * are the ones this user's own exports produced — a path traversal has
 * nowhere to go because the caller never supplies a path.
 *
 * Exports live on a private disk for this reason. A public disk would put a
 * copy of records somebody was allowed to see at a URL anybody can guess, and
 * an export is exactly the kind of file that is worth guessing at.
 */
final class PanelExportController
{
    public function __invoke(Request $request, string $file): StreamedResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        // A name, never a path. A separator or a dot-segment is not a file
        // this endpoint issued, whatever it would resolve to.
        abort_if(
            $file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..'),
            404,
        );

        $exporter = $request->query('exporter');

        abort_unless(
            is_string($exporter) && is_subclass_of($exporter, Exporter::class),
            404,
            __('panda-panel::errors.unknown_export'),
        );

        $disk = Storage::disk($exporter::disk());
        $path = $exporter::directory().'/'.$user->getAuthIdentifier().'/'.$file;

        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $file);
    }
}
