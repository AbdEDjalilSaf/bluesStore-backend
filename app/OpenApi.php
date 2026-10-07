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
#[OA\SecurityScheme(
    securityScheme: 'bearer',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
)]
#[OA\Tag(
    name: 'Auth',
    description: 'Authentication endpoints',
)]
#[OA\Get(
    path: '/user',
    summary: 'Show the authenticated user.',
    tags: ['Auth'],
    security: [['bearer' => []]],
    responses: [
        new OA\Response(response: 200, description: 'Authenticated user information'),
        new OA\Response(response: 401, description: 'Not authenticated'),
    ],
)]
class OpenApi
{
}
