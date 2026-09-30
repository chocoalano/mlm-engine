<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Periods;

use PandaPanel\Resources\Pages\ListRecords;

final class ListCommissionPeriods extends ListRecords
{
    protected static string $resource = CommissionPeriodResource::class;
}
