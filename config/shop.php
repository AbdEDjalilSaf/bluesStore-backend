<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shop Configuration
    |--------------------------------------------------------------------------
    |
    | Central place for shop-wide settings, such as the free shipping
    | threshold and the currency used across the storefront.
    |
    */

    'free_shipping_threshold' => 10000,

    'currency' => 'DZD',

    /*
    |--------------------------------------------------------------------------
    | Admin Token
    |--------------------------------------------------------------------------
    |
    | Shared secret sent in the X-Admin-Token header by the admin dashboard.
    | When this is empty every admin endpoint is refused.
    |
    */

    'admin_token' => env('SHOP_ADMIN_TOKEN'),

];
