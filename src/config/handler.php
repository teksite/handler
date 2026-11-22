<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FetchData
    |--------------------------------------------------------------------------
    |
    | pagination             => default number of items per page. Higher numbers may
    |                           affect server performance; 50 is the suggested value.
    | limit-pagination       => hard cap for items per page (also applied to the value
    |                           requested by the client). Set it to false to disable the cap.
    | search_input_field     => request input that holds the search keyword.
    | per_page_query         => request input that holds the requested page size.
    | default_order_by       => column used when no (valid) ordering is requested.
    | default_sort_direction => 'asc' or 'desc'.
    |
    | The HANDLER_* environment variables take priority; the older PAGINATE_*
    | names are still honoured as a fallback.
    |
    */
    'pagination' => env('HANDLER_PAGINATION', 50),

    'limit-pagination' => env('HANDLER_LIMIT_PAGINATION', 250),

    'search_input_field' => 's',

    'per_page_query' => 'per_page',

    'default_order_by' => 'created_at',

    'default_sort_direction' => 'desc',

    'search_input_field_length' => 250,

    /*
    |--------------------------------------------------------------------------
    | ServiceWrapper
    |--------------------------------------------------------------------------
    |
    | wrapper              => global switch for the exception handling of every
    |                         ServiceWrapper. When false, exceptions are not caught and
    |                         bubble up with their full trace (meant for development and
    |                         debugging). Transactions and result wrapping are not affected.
    | transaction          => run the action inside a database transaction by default.
    | service_result       => wrap the outcome in a service result object by default.
    | service_result_class => class used for wrapping; it must implement
    |                         \Teksite\Handler\Contracts\ServiceResultContract.
    | connection           => database connection used for transactions;
    |                         null means the application's default connection.
    | log                  => log caught exceptions and event dispatch problems.
    |
    */
    'wrapper'                   => env('HANDLER_WRAPPER', true),

    'transaction' => env('HANDLER_TRANSACTION', true),

    'service_result' => env('HANDLER_USE_RESULT_SERVICE', true),

    "service_result_class" => \Teksite\Handler\Data\ServiceResult::class,

    'connection' => env('HANDLER_DB_CONNECTION') ?? env('DB_CONNECTION'),

    'log' => env('HANDLER_LOG', true),


    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Dispatched by ServiceWrapper::run() when requested. A success event class
    | must implement \Teksite\Handler\Contracts\SuccessEventContract and a failure
    | event class \Teksite\Handler\Contracts\FailureEventContract. Set a value to
    | null to disable it.
    |
    */

    "success_event_class" => "Teksite\\Handler\\Events\\OnSuccessEvent",

    "failure_event_class" => "Teksite\\Handler\\Events\\OnFailureEvent",
];
