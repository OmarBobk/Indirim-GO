<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerActivityCategory: string
{
    case Orders = 'orders';
    case Money = 'money';
    case Rewards = 'rewards';
    case Account = 'account';
}
