<?php

declare(strict_types=1);

namespace PandaPanel\Enums;

/**
 * The points in the panel shell that accept injected components.
 *
 * A closed set rather than free strings: a hook registered against a name the
 * shell does not render would silently do nothing, and that is exactly the
 * kind of failure this framework refuses elsewhere.
 *
 * Filament injects Blade here. Nothing renderable can cross the wire in this
 * framework, so a hook names a Vue component that a build-time registry
 * resolves, and carries serializable props.
 */
enum RenderHook: string
{
    case BodyStart = 'body.start';
    case BodyEnd = 'body.end';
    case SidebarStart = 'sidebar.start';
    case SidebarEnd = 'sidebar.end';
    case HeaderStart = 'header.start';
    case HeaderEnd = 'header.end';
    case PageStart = 'page.start';
    case PageEnd = 'page.end';
}
