<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Enums;

/**
 * How the frontend runs an action.
 *
 * `Link` navigates to a URL the server produced. `Callback` posts an action
 * identifier to the action endpoint, where the backend resolves what to run.
 * `Form` opens a dialog whose schema and submit URL are fetched from the
 * server when the dialog opens, so a table of twenty rows ships twenty
 * buttons rather than twenty forms.
 *
 * The frontend never receives anything executable in any of the three.
 */
enum ActionType: string
{
    case Link = 'link';
    case Callback = 'callback';
    case Form = 'form';
}
