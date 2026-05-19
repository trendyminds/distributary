<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display Name
    |--------------------------------------------------------------------------
    |
    | The name shown to content authors — in the CP utility list, nav, and on
    | each page of the import flow. The package's internal handle remains
    | "distributary" regardless.
    |
    */

    'display_name' => env('DISTRIBUTARY_DISPLAY_NAME', 'AI Import'),

    /*
    |--------------------------------------------------------------------------
    | AI Provider & Model
    |--------------------------------------------------------------------------
    |
    | The laravel/ai provider name and model to use for block mapping. The
    | provider must be configured in config/ai.php. Leave provider null to
    | use the default provider from config/ai.php.
    |
    */

    'provider' => env('DISTRIBUTARY_PROVIDER', 'anthropic'),
    'model' => env('DISTRIBUTARY_MODEL', 'claude-opus-4-7'),

];
