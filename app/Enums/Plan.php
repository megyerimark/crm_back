<?php

namespace App\Enums;

enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';

    public function isPaid(): bool
    {
        return $this !== self::Free;
    }
}
