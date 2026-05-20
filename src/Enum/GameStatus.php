<?php

namespace App\Enum;

enum GameStatus: string
{
    case Open     = 'open';
    case Closed   = 'closed';
    case Revealed = 'revealed';
}
