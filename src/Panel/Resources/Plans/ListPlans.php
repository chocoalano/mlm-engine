<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Plans;

use PandaPanel\Resources\Pages\ListRecords;

final class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;
}
