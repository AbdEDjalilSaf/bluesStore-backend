<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'My API',
    description: 'OpenAPI documentation for the storefront API.',
    contact: new OA\Contact(
        email: 'hello@example.com'
    ),
)]
#[OA\Server(
    url: '/api',
    description: 'API server',
)]
class OpenApi {}
