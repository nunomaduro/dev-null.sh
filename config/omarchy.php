<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Omarchy Theme
    |--------------------------------------------------------------------------
    |
    | When enabled, the app adopts the colors of the currently active Omarchy
    | desktop theme. Everything below degrades silently: if the colors file
    | cannot be found, the "Omarchy" appearance option is simply unavailable.
    |
    */

    'enabled' => (bool) env('OMARCHY_THEME', true),

    /*
    |--------------------------------------------------------------------------
    | Omarchy Theme Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the colors file of the applied Omarchy theme. A glob is
    | allowed and the first match wins, so the app works for any host user
    | without configuration. Set OMARCHY_THEME_PATH to override it.
    |
    */

    'path' => env('OMARCHY_THEME_PATH', '/home/*/.local/state/omarchy/current/theme/colors.toml'),

    /*
    |--------------------------------------------------------------------------
    | Omarchy Font Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the fontconfig file `omarchy font set` writes. The
    | preferred monospace family is read from it and applied to the app.
    |
    */

    'font_path' => env('OMARCHY_FONT_PATH', '/home/*/.config/fontconfig/fonts.conf'),

    /*
    |--------------------------------------------------------------------------
    | Omarchy Font
    |--------------------------------------------------------------------------
    |
    | Explicit font family to use instead of detecting it. When null the
    | family is read from the fontconfig file above.
    |
    */

    'font' => env('OMARCHY_FONT') ?: null,

];
