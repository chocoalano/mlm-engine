<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use PandaPanel\Resources\Pages\ViewRecord;

final class ViewPayoutRequest extends ViewRecord
{
    protected static string $resource = PayoutRequestResource::class;
}
