<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application credentials
    |--------------------------------------------------------------------------
    | Register an application at https://my.starline.ru/developer to obtain
    | the App ID and Secret Key. Both are required.
    */

    'app_id' => env('STARLINE_APP_ID'),
    'app_secret' => env('STARLINE_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | StarLine account credentials
    |--------------------------------------------------------------------------
    */

    'login' => env('STARLINE_LOGIN'),
    'password' => env('STARLINE_PASSWORD'),

    'user_id' => env('STARLINE_USER_ID'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('STARLINE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    | SLID tokens are cached so the full auth chain runs only when needed.
    | "store" = null means the default cache store.
    */

    'cache' => [
        'store' => env('STARLINE_CACHE_STORE'),
        'prefix' => env('STARLINE_CACHE_PREFIX', ''),
        'ttl' => (int) env('STARLINE_CACHE_TTL', 86400),
    ],

];
