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
    "pagination" => 50, //number of items per page

    "client-pagination" => 25, //number of items per page in client side

    'limit-pagination' => true, // to prevent data usage max number of items is 250

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
    "wrapper" => true // activating wrapper (try-catch) globally.
];
