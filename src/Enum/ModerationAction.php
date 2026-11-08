<?php

namespace App\Enum;

enum ModerationAction: string
{
    case GameHidden   = 'game_hidden';
    case GameRestored = 'game_restored';
    case UserDeleted  = 'user_deleted';
}
