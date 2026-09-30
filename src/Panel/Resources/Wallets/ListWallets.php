<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Panel\Resources\Wallets;

use PandaPanel\Resources\Pages\ListRecords;

final class ListWallets extends ListRecords
{
    protected static string $resource = WalletResource::class;
}
