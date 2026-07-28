<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerActivityStatusToken: string
{
    case Neutral = 'neutral';
    case Progress = 'progress';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
