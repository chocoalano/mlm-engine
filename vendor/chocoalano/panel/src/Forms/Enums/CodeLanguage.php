<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Enums;

/**
 * The grammar a code editor highlights with.
 *
 * Closed, because each case maps to a Monaco grammar the frontend names
 * explicitly. A free string would be a request for a language id that may not
 * be registered, and an unregistered id is not an error — it is an editor that
 * silently highlights nothing, which looks exactly like a grammar that is
 * missing.
 */
enum CodeLanguage: string
{
    case Plain = 'plain';
    case Json = 'json';
    case Html = 'html';
    case Css = 'css';
    case JavaScript = 'javascript';
    case Php = 'php';
    case Sql = 'sql';
    case Yaml = 'yaml';
    case Markdown = 'markdown';
}
