<?php

declare(strict_types=1);

return [

    /*
     * Where the authorize page may send the devnull app back to with its code. A redirect_uri has to start with one of
     * these, so a code never leaves for anywhere but the app's own URL scheme.
     */
    'redirect_uri_prefixes' => ['devnull://'],

];
