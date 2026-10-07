<?php

namespace App\Enums;

enum Rarity: string
{
    case VeryRare = 'very_rare';
    case Rare = 'rare';
    case Iconic = 'iconic';
    case Limited = 'limited';
}
