<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Amazon Photos Credentials
    |--------------------------------------------------------------------------
    |
    | These cookies must be extracted manually from an authenticated Amazon
    | Photos browser session. They are required for API authentication.
    |
    */

    'session_id' => env('AMAZON_PHOTOS_SESSION_ID', ''),

    'ubid' => env('AMAZON_PHOTOS_UBID', ''),

    'at' => env('AMAZON_PHOTOS_AT', ''),

    /*
    |--------------------------------------------------------------------------
    | Amazon Region TLD
    |--------------------------------------------------------------------------
    |
    | The top-level domain for your Amazon account region.
    | Examples: com (US), ca (Canada), co.uk (UK), de (Germany), fr (France),
    |           it (Italy), es (Spain), co.jp (Japan), com.au (Australia)
    |
    */

    'tld' => env('AMAZON_PHOTOS_TLD', 'com'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    */

    'timeout' => 60,
    'connect_timeout' => 10,
    'retry_times' => 3,
    'retry_sleep_ms' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'page_size' => 200,

    /*
    |--------------------------------------------------------------------------
    | Cache / History Settings
    |--------------------------------------------------------------------------
    |
    | Path (relative to storage/app) where analysis history is persisted.
    |
    */

    'history_file' => env('AMAZON_PHOTOS_HISTORY_FILE', 'amazon-photos/analysis-history.json'),

];
