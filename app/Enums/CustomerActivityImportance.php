<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerActivityImportance: string
{
    case Urgent = 'urgent';
    case Attention = 'attention';
    case Success = 'success';
    case Informational = 'informational';
}
