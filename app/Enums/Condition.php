<?php

namespace App\Enums;

enum Condition: string
{
    case MintWithTags = 'mint_with_tags';
    case Excellent = 'excellent';
    case VeryGood = 'very_good';
}
