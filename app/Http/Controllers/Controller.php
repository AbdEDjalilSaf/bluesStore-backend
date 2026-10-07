<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'products', description: 'Shirt catalog')]
#[OA\Tag(name: 'wilayas', description: 'Shipping destinations')]
#[OA\Tag(name: 'orders', description: 'Guest checkout')]
abstract class Controller
{
    //
}
