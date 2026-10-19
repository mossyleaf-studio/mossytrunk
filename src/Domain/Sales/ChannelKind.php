<?php

declare(strict_types=1);

namespace App\Domain\Sales;

enum ChannelKind: string
{
    case Market = 'market';
    case Online = 'online';
    case Direct = 'direct';
}
