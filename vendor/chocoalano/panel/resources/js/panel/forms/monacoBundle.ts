import { loader } from '@guolao/vue-monaco-editor';
import * as monaco from 'monaco-editor';
import EditorWorker from 'monaco-editor/editor/editor.worker?worker';
import CssWorker from 'monaco-editor/language/css/css.worker?worker';
import HtmlWorker from 'monaco-editor/language/html/html.worker?worker';
import JsonWorker from 'monaco-editor/language/json/json.worker?worker';
import TypeScriptWorker from 'monaco-editor/language/typescript/ts.worker?worker';

/**
 * Monaco, from the application's own build rather than from a CDN.
 *
 * `@guolao/vue-monaco-editor` defaults to fetching the editor from unpkg at
 * runtime, and that default is wrong for every deployment this package cares
 * about. A panel that downloads its code editor when a form opens is a panel
 * that shows an empty box behind a firewall, under a `Content-Security-Policy`
 * that names its own origins, and on any air-gapped install — and it leaks the
 * fact that somebody opened that form to a third party. `loader.config()` takes
 * an instance instead, which is what this module hands it.
 *
 * **This module is the chunk boundary, and that is its whole reason for
 * existing.** Monaco is large. Everything here is behind a dynamic `import()`
 * in `CodeEditorField`, so a panel with no code field never downloads a byte of
 * it, and the language grammars are lazy inside Monaco itself — opening a PHP
 * field fetches the PHP grammar and not the other eighty.
 *
 * Nothing in here may be imported statically from a component.
 */

/**
 * The workers, by the label Monaco asks for.
 *
 * Language services run off the main thread, and a missing worker is not a
 * crash — it is an editor that quietly stops validating, which is worse,
 * because the `json` rule then refuses on submit something the editor said
 * nothing about. Vite compiles each `?worker` import into its own bundle, so
 * these are five more lazy chunks rather than five more megabytes.
 *
 * `javascript` is served by the TypeScript worker: Monaco registers one
 * language service for both, and JavaScript is the half of it this panel's
 * `CodeLanguage` enum offers.
 */
const WORKERS: Record<string, new () => Worker> = {
    css: CssWorker,
    scss: CssWorker,
    less: CssWorker,
    html: HtmlWorker,
    handlebars: HtmlWorker,
    razor: HtmlWorker,
    json: JsonWorker,
    javascript: TypeScriptWorker,
    typescript: TypeScriptWorker,
};

let configured = false;

/**
 * Idempotent, because every code field on a page calls it. Monaco is a single
 * global instance and configuring it twice is not a second instance — it is a
 * second `MonacoEnvironment`, which the first editor is already holding.
 */
export function configureMonaco(): void {
    if (configured) {
        return;
    }

    configured = true;

    (
        self as unknown as {
            MonacoEnvironment: {
                getWorker: (workerId: string, label: string) => Worker;
            };
        }
    ).MonacoEnvironment = {
        getWorker: (_workerId, label) => new (WORKERS[label] ?? EditorWorker)(),
    };

    loader.config({ monaco });
}
