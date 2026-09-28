<?php

declare(strict_types=1);

namespace PandaPanel\Enums;

/**
 * Where a cluster's sub-navigation is drawn.
 *
 * Closed, because each case maps to a place in the shell the build already
 * knows about. A cluster is a set of pages that belong together, and where
 * that set is listed is a layout decision the panel makes once.
 */
enum ClusterPosition: string
{
    /** A bar under the header, above the page's own content. */
    case Header = 'header';

    /** A column beside the content, on the right. */
    case RightBar = 'right-bar';

    /** Only in the sidebar, expanded under the cluster's own item. */
    case Sidebar = 'sidebar';
}
