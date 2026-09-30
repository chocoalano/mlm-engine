<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Payouts;

use PandaPanel\Resources\Pages\ListRecords;

final class ListPayoutRequests extends ListRecords
{
    protected static string $resource = PayoutRequestResource::class;
}
