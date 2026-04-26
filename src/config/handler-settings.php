<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | "pagination" This value is the number of items per page.
    |  Higher numbers may affect on the performance of your server, our suggest is
    |  "50". of coarse to prevent it "limit-pagination" is considered
    |  "limit-pagination" is for this reason to not load items more than 250, if it is false
    |   there is no limitation.
    */
    "pagination" => env('PAGINATE_PER_PAGE', 50), //number of items per page

    "client-pagination" => env('PAGINATE_CLIENT_PER_PAGE', 25), //number of items per page in client side

    'limit-pagination' => env('PAGINATE_LIMITATION', 250), // to prevent data usage max number of items is 250

    /*
    |--------------------------------------------------------------------------
    | wrapper
    |--------------------------------------------------------------------------
    |
    | "wrapper" active the ServiceWrapper
    | CAUTION deactivating this parameter, cause deactivating all ServiceWrappers in the
    | entire of the app
    |
    */
    "wrapper"          => env('HANDLER_WRAPPER', true),// activating wrapper (error handling / try-catch) globally.
    "transaction"      => env('HANDLER_TRANSACTION', true), // activating wrapper (error handling / try-catch) globally.
    "service_result"      => env('HANDLER_USE_RESULT_SERVICE', true), // activating wrapper (error handling / try-catch) globally.
];
