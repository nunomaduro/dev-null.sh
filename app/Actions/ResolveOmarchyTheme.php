<?php

declare(strict_types=1);

namespace App\Actions;

use App\Omarchy\OmarchyTheme;
use Illuminate\Support\Str;

final readonly class ResolveOmarchyTheme
{
    public function handle(): ?OmarchyTheme
    {
        if (! config()->boolean('omarchy.enabled')) {
            return null;
        }

        $path = $this->locate($this->setting('omarchy.path'));

        if ($path === null) {
            return null;
        }

        $colors = $this->parse((string) file_get_contents($path));

        if (! isset($colors['background'], $colors['foreground'])) {
            return null;
        }

        $mode = ($colors['mode'] ?? 'dark') === 'light' ? 'light' : 'dark';

        return new OmarchyTheme(
            name: $this->name($path),
            mode: $mode,
            font: $this->font(),
            tokens: $this->tokens($colors, $mode),
        );
    }

    private function name(string $path): string
    {
        $file = dirname($path).'/theme.name';

        $slug = is_file($file) ? mb_trim((string) file_get_contents($file)) : '';

        return Str::headline($slug !== '' ? $slug : 'Omarchy');
    }

    private function font(): ?string
    {
        $configured = config('omarchy.font');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $path = $this->locate($this->setting('omarchy.font_path'));

        if ($path === null) {
            return null;
        }

        if (preg_match_all('/<string>\s*([^<]+?)\s*<\/string>/', (string) file_get_contents($path), $matches) < 1) {
            return null;
        }

        foreach ($matches[1] as $family) {
            $family = mb_trim($family);

            if ($family !== '' && mb_strtolower($family) !== 'monospace') {
                return $family;
            }
        }

        return null;
    }

    private function locate(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (! str_contains($path, '*')) {
            return is_file($path) ? $path : null;
        }

        $matches = glob($path);

        if ($matches === false || $matches === []) {
            return null;
        }

        return $matches[0];
    }

    private function setting(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $contents): array
    {
        $colors = [];

        foreach (explode("\n", $contents) as $line) {
            $line = mb_trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = mb_strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = mb_trim(mb_substr($line, 0, $position));
            $value = mb_trim(mb_substr($line, $position + 1), " \t\"'");

            if ($key === '' || $value === '') {
                continue;
            }

            $colors[$key] = $value;
        }

        return $colors;
    }

    /**
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    private function tokens(array $colors, string $mode): array
    {
        $background = $colors['background'];
        $foreground = $colors['foreground'];
        $primary = $this->color($colors, 'accent', $foreground);

        return [
            '--background' => $background,
            '--foreground' => $foreground,
            '--card' => $this->color($colors, 'lighter_background', $background),
            '--card-foreground' => $foreground,
            '--popover' => $this->color($colors, 'lighter_background', $background),
            '--popover-foreground' => $this->color($colors, 'bright_foreground', $foreground),
            '--primary' => $primary,
            '--primary-foreground' => $background,
            '--primary-hover' => $mode === 'dark'
                ? 'color-mix(in oklab, var(--primary) 82%, #ffffff)'
                : 'color-mix(in oklab, var(--primary) 88%, #000000)',
            '--primary-soft' => $this->color($colors, 'selection', $background),
            '--secondary' => $this->color($colors, 'lighter_background', $background),
            '--secondary-foreground' => $foreground,
            '--muted' => $this->color($colors, 'lighter_background', $background),
            '--muted-foreground' => $this->color($colors, 'muted', $this->color($colors, 'dark_foreground', $foreground)),
            '--accent' => $this->color($colors, 'lighter_background', $background),
            '--accent-foreground' => $foreground,
            '--destructive' => $this->color($colors, 'red', $foreground),
            '--destructive-foreground' => $this->color($colors, 'bright_red', $this->color($colors, 'red', $foreground)),
            '--border' => $this->color($colors, 'hyprland_inactive_border', $this->color($colors, 'selection', $background)),
            '--input' => $this->color($colors, 'selection', $background),
            '--ring' => $primary,
            '--rail' => $this->color($colors, 'selection', $background),
            '--lane-0' => $this->color($colors, 'bright_red', $this->color($colors, 'red', $foreground)),
            '--lane-1' => $this->color($colors, 'bright_green', $this->color($colors, 'green', $foreground)),
            '--lane-2' => $this->color($colors, 'bright_magenta', $this->color($colors, 'magenta', $foreground)),
            '--lane-3' => $this->color($colors, 'bright_cyan', $this->color($colors, 'cyan', $foreground)),
            '--lane-4' => $this->color($colors, 'bright_yellow', $this->color($colors, 'yellow', $foreground)),
            '--lane-5' => $this->color($colors, 'bright_blue', $this->color($colors, 'blue', $foreground)),
        ];
    }

    /**
     * @param  array<string, string>  $colors
     */
    private function color(array $colors, string $key, string $default): string
    {
        $value = $colors[$key] ?? '';

        return $value !== '' ? $value : $default;
    }
}
