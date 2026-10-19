<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The ingestion origin (scheme + host, no path) and a SERVER write key
    | (`keyId:secret`). A browser key is accepted by ingestion but makes every
    | event report your server's IP instead of your customer's.
    |
    */

    'enabled' => env('SFERE_ENABLED', true),

    'host' => env('SFERE_HOST'),

    'write_key' => env('SFERE_WRITE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | sync:  one request per event, inside the call (console, debugging).
    | batch: buffer in memory, send to /v1/batch at the end of the request,
    |        job or command, or when the buffer is full or old.
    | queue: buffer, then dispatch a SendEvents job per batch.
    |
    */

    'delivery' => env('SFERE_DELIVERY', 'batch'),

    'batch' => [
        'max_size' => 100,          // capped at 100
        'max_bytes' => 500_000,     // capped at 500 KB
        'max_wait_seconds' => 10,
    ],

    'queue' => [
        'connection' => env('SFERE_QUEUE_CONNECTION'),
        'name' => env('SFERE_QUEUE', 'default'),
        'tries' => 5,
        'backoff' => [10, 30, 60, 120],
    ],

    'http' => [
        'timeout' => 3,
        'connect_timeout' => 2,
        'retries' => 3,
        'max_retry_after' => 10,
        'compression' => false,     // gzip /v1/batch bodies only
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Ingestion never fills in the IP, user agent or click IDs of server
    | events. Without them, conversions reach ad platforms unmatched.
    |
    */

    'context' => [
        'include_request' => true,                  // ip, userAgent, locale, page, campaign, clientIds
        'trust_proxy' => true,                      // left-most X-Forwarded-For; false = $request->ip()
        'anonymous_id_cookie' => 'ajs_anonymous_id', // null to disable (experimental)
        'static' => [],                             // merged into every event's context
    ],

    'throw_exceptions' => env('SFERE_THROW', false),

    'log_channel' => env('SFERE_LOG_CHANNEL'),

];
