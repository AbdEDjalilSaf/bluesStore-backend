<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Shirt Store API',
    description: 'No-login storefront API for a retro football shirt shop. Browse the catalog and shipping wilayas, then place an order without an account. All amounts are in DZD.',
    contact: new OA\Contact(
        email: 'hello@example.com'
    ),
)]
class OpenApi {}
