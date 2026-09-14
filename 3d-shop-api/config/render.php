<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Host storage path
    |--------------------------------------------------------------------------
    |
    | Where the storage directory lives on the Docker host. The broker starts
    | containers as siblings, so `-v` arguments are resolved by the host daemon,
    | to which a path inside a container means nothing. Left empty, the daemon is
    | asked for its own mounts instead, which is right in every setup so far.
    |
    */

    'host_storage' => env('RENDER_HOST_STORAGE', ''),

];
