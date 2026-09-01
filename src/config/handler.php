<?php
return [
    /*
    |--------------------------------------------------------------------------
    | FetchData
    |--------------------------------------------------------------------------
    |
    | "pagination" This value is the number of items per page.
    |  Higher numbers may affect the performance of your server, our suggestion is
    |  "50". Of course, to prevent it "limit-pagination" is considered.
    |  "limit-pagination" is for this reason: to not load more than 250 items.
    |  if it is false, there is no limitation.
    */
    "pagination" => env('PAGINATE_PER_PAGE', 50), // number of items per page

    "client-pagination" => env('PAGINATE_CLIENT_PER_PAGE', 25), // number of items per page in client side

    'limit-pagination' => env('PAGINATE_LIMITATION', 250), // to prevent data usage, max number of items is 250

    'search_input_field' => 's', // search based on the input of request

    'per_page_query' => 'per_page', // search based on the input of request

    'default_order_by' => 'created_at',

    'default_sort_direction' => 'desc', // or 'asc'

    'allow_relation_ordering' => false, // Allow order by relations? (performance consideration)
    /*
    |--------------------------------------------------------------------------
    | wrapper
    |--------------------------------------------------------------------------
    |
    | wrapper         => active wrapper (error handling / try-catch) globally
    | CAUTION deactivating this parameter causes deactivation of all ServiceWrappers in the entire app
    | transaction     => active transaction in multi-query database operations (error handling / try-catch and db transaction) globally.
    | service_result  => unify result of all processes globally.
    | connection      => the default DB connection used for transactions. null = use the app's default connection.
    |
    */

    "wrapper"              => env('HANDLER_WRAPPER', true),

    "transaction"          => env('HANDLER_TRANSACTION', true),

    "service_result"       => env('HANDLER_USE_RESULT_SERVICE', true),

    "service_result_class" => \Teksite\Handler\Data\ServiceResult::class,

    "connection"           => env('HANDLER_DB_CONNECTION') ?? env('DB_CONNECTION'), // e.g. 'mysql', 'pgsql', 'tenant', ...

    "log"                  => env('HANDLER_LOG', true),

    /**
     *
     * run the event if the operation succeeded or failed
     *
     */

    "success_event_class" => "Teksite\\Handler\\Events\\OnSuccessEvent",

    "failure_event_class" => "Teksite\\Handler\\Events\\OnFailureEvent",
];
