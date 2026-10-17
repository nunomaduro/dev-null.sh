<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use GdImage;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Draws link preview images in the light Commit Graph theme. Everything is drawn at twice the size and scaled down, since GD only anti-aliases text.
 */
final readonly class OpenGraphCanvas
{
    public const int WIDTH = 1200;

    public const int HEIGHT = 630;

    public const int INK = 0x121417;

    public const int INK_MUTED = 0x5B616B;

    public const int RAIL_X = 112;

    public const int CONTENT_X = 184;

    public const int CONTENT_RIGHT = 1104;

    public const int FOOTER_RULE_Y = 456;

    public const int FOOTER_BASELINE = 524;

    private const int SCALE = 2;

    private const int SHELL = 0xF6F7F8;

    private const int HAIRLINE = 0xE1E4E8;

    private const int RAIL = 0xD3D7DD;

    private const int WHITE = 0xFFFFFF;

    private const array LANES = [0xD9480F, 0x2F9E44, 0x9C36B5, 0x0C8599, 0xC2255C, 0xB7791F];

    private const int MAX_AVATAR_BYTES = 2_097_152;

    /**
     * Non-public IP ranges: carrier-grade NAT, IETF protocol assignments, 6to4 relays,
     * benchmarking, documentation, multicast for IPv4; NAT64, discard, Teredo, documentation,
     * site-local, multicast, and IPv4-translated addresses for IPv6.
     *
     * @var list<string>
     */
    private const array BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'fec0::/10',
        'ff00::/8',
        '::ffff:0:0:0/96',
    ];

    private GdImage $image;

    public function __construct()
    {
        $this->image = $this->blank(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
    }

    public function lane(User $user): int
    {
        return self::LANES[$user->lane()];
    }

    /**
     * The rail runs gray down to the first node and in the lane after it.
     */
    public function rail(int $y, int $lane): void
    {
        $this->rect(self::RAIL_X - 2, 0, self::RAIL_X + 2, $y, self::RAIL);
        $this->rect(self::RAIL_X - 2, $y, self::RAIL_X + 2, self::HEIGHT, $lane);
        $this->node($y, $lane);
    }

    public function node(int $y, int $lane): void
    {
        $this->circle(self::RAIL_X, $y, 16, $lane);
        $this->circle(self::RAIL_X, $y, 12, self::SHELL);
        $this->circle(self::RAIL_X, $y, 8, $lane);
    }

    /**
     * @param  positive-int  $size
     */
    public function avatar(User $user, int $x, int $y, int $size, int $lane): void
    {
        $radius = $size / 2;
        $centerX = $x + $radius;
        $centerY = $y + $radius;

        $this->circle($centerX, $centerY, $radius + 10, $lane);
        $this->circle($centerX, $centerY, $radius + 6, self::SHELL);

        $source = $this->download($user->avatar_url);

        if (! $source instanceof GdImage) {
            $this->circle($centerX, $centerY, $radius, $lane);

            $initial = mb_strtoupper(mb_substr($this->name($user), 0, 1));
            $fontSize = (int) round($size * 0.474);
            $box = $this->box($initial, 'Geist-SemiBold', $fontSize);

            $this->text($initial, 'Geist-SemiBold', $fontSize, (int) round($centerX - ($box['left'] + $box['right']) / 2), (int) round($centerY - ($box['top'] + $box['bottom']) / 2), self::WHITE);

            return;
        }

        $pixels = $size * self::SCALE;
        $avatar = $this->blank($pixels, $pixels);
        imagecopyresampled($avatar, $source, 0, 0, 0, 0, $pixels, $pixels, imagesx($source), imagesy($source));

        $center = ($pixels - 1) / 2;

        for ($row = 0; $row < $pixels; $row++) {
            for ($column = 0; $column < $pixels; $column++) {
                $color = imagecolorat($avatar, $column, $row);

                if ($color !== false && ($column - $center) ** 2 + ($row - $center) ** 2 <= ($pixels / 2) ** 2) {
                    imagesetpixel($this->image, $x * self::SCALE + $column, $y * self::SCALE + $row, $color);
                }
            }
        }
    }

    /**
     * The mono handle, led by a dot in the user's lane.
     */
    public function handle(User $user, int $size, int $x, int $y, int $width, int $lane): void
    {
        $this->circle($x + $size * 0.22, $y - $size * 0.34, $size * 0.22, $lane);

        $offset = (int) round($size * 0.875);

        $this->text($this->truncate('@'.$user->username, 'GeistMono-Regular', $size, $width - $offset), 'GeistMono-Regular', $size, $x + $offset, $y, self::INK_MUTED);
    }

    /**
     * A mono number followed by its sans label, returning where the next one starts.
     */
    public function count(int $count, string $label, int $x): int
    {
        $number = number_format($count);

        $this->text($number, 'GeistMono-Medium', 30, $x, self::FOOTER_BASELINE, self::INK);
        $x += $this->width($number, 'GeistMono-Medium', 30) + 10;

        $this->text($label, 'Geist-Regular', 30, $x, self::FOOTER_BASELINE, self::INK_MUTED);

        return $x + $this->width($label, 'Geist-Regular', 30) + 36;
    }

    /**
     * The hairline above the footer and the /dev/null wordmark on its right.
     */
    public function footer(): void
    {
        $this->rect(self::CONTENT_X, self::FOOTER_RULE_Y, self::CONTENT_RIGHT, self::FOOTER_RULE_Y + 2, self::HAIRLINE);

        $label = '/dev/null';
        $x = self::CONTENT_RIGHT - $this->width($label, 'Geist-SemiBold', 32);

        $this->text($label, 'Geist-SemiBold', 32, $x, self::FOOTER_BASELINE, self::INK);

        $size = 32;
        $left = $x - 8 - $size;
        $top = self::FOOTER_BASELINE - 11 - $size / 2;

        foreach ([
            [[[2, 42], [12, 42], [26, 6], [16, 6]], self::INK],
            [[[17.81, 43.5], [31.03, 43.5], [46.19, 4.5], [32.97, 4.5]], self::LANES[0]],
            [[[22.19, 40.5], [35.03, 7.5], [41.81, 7.5], [28.97, 40.5]], self::SHELL],
        ] as [$corners, $color]) {
            $this->polygon($corners, $left, $top, $size, $color);
        }
    }

    /**
     * The user's name as Geist can draw it, or their username when nothing is left.
     */
    public function name(User $user): string
    {
        $name = $this->clean($user->name);

        return $name === '' ? $user->username : $name;
    }

    /**
     * Wraps every line of the text on words, and long unbroken words (URLs) by character.
     *
     * @return list<string>
     */
    public function wrap(string $text, string $font, int $size, int $width): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $lines = [...$lines, ...$this->wrapParagraph($this->clean($paragraph), $font, $size, $width)];
        }

        return $lines;
    }

    /**
     * Keeps the first lines, ending the last one kept with an ellipsis when some are cut.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function clamp(array $lines, int $count, string $font, int $size, int $width): array
    {
        if (count($lines) <= $count) {
            return $lines;
        }

        return [
            ...array_slice($lines, 0, $count - 1),
            $this->truncate($lines[$count - 1], $font, $size, $width, force: true),
        ];
    }

    public function truncate(string $text, string $font, int $size, int $width, bool $force = false): string
    {
        if (! $force && $this->width($text, $font, $size) <= $width) {
            return $text;
        }

        while ($text !== '' && $this->width($this->ellipsis($text), $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $this->ellipsis($text);
    }

    /**
     * Geist has no emoji, so they are dropped rather than rendered as empty boxes.
     */
    public function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{Extended_Pictographic}\x{FE0F}\x{200D}\x{20E3}]/u', '', $text);

        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function text(string $text, string $font, int $size, int $x, int $y, int $color): void
    {
        imagettftext($this->image, $this->points($size), 0, $x * self::SCALE, $y * self::SCALE, $color, $this->font($font), $text);
    }

    public function width(string $text, string $font, int $size): int
    {
        $box = $this->box($text, $font, $size);

        return $box['right'] - $box['left'];
    }

    public function png(): string
    {
        $output = $this->blank(self::WIDTH, self::HEIGHT);

        imagecopyresampled($output, $this->image, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, imagesx($this->image), imagesy($this->image));

        ob_start();
        imagepng($output);

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function wrapParagraph(string $text, string $font, int $size, int $width): array
    {
        $lines = [''];

        foreach (explode(' ', $text) as $word) {
            $current = array_key_last($lines);
            $candidate = mb_ltrim($lines[$current].' '.$word);

            if ($this->width($candidate, $font, $size) <= $width) {
                $lines[$current] = $candidate;

                continue;
            }

            if ($this->width($word, $font, $size) <= $width) {
                $lines[] = $word;

                continue;
            }

            if ($lines[$current] !== '') {
                $lines[$current] .= ' ';
            }

            foreach (mb_str_split($word) as $character) {
                $current = array_key_last($lines);

                if ($this->width($lines[$current].$character, $font, $size) > $width) {
                    $lines[] = '';
                    $current++;
                }

                $lines[$current] .= $character;
            }
        }

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private function download(?string $url): ?GdImage
    {
        if ($url === null || $url === '') {
            return null;
        }

        if ($localFile = $this->localFile($url)) {
            $data = @file_get_contents($localFile);

            if ($data === false || mb_strlen($data) > self::MAX_AVATAR_BYTES || @getimagesizefromstring($data) === false) {
                return null;
            }

            $avatar = @imagecreatefromstring($data);

            return $avatar instanceof GdImage ? $avatar : null;
        }

        if (! $this->isAllowedRemote($url)) {
            return null;
        }

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->withOptions([
                    'progress' => fn (int $expected, int $downloaded): bool => $downloaded > self::MAX_AVATAR_BYTES,
                ])
                ->get($url);
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if (! $response->successful()
            || mb_strlen($response->body()) > self::MAX_AVATAR_BYTES
            || @getimagesizefromstring($response->body()) === false) {
            return null;
        }

        $avatar = @imagecreatefromstring($response->body());

        return $avatar instanceof GdImage ? $avatar : null;
    }

    /**
     * Resolves local public asset paths (such as Tom's avatar) directly from disk, preventing unnecessary HTTP loopback calls.
     */
    private function localFile(string $url): ?string
    {
        $assetBase = asset('');

        if (! str_starts_with($url, $assetBase)) {
            return null;
        }

        $path = mb_substr($url, mb_strlen($assetBase));

        if (str_contains($path, '?')) {
            $path = (string) mb_strstr($path, '?', true);
        }

        $fullPath = public_path($path);
        $realPublic = realpath(public_path());
        $realFile = realpath($fullPath);

        if ($realPublic !== false && $realFile !== false && str_starts_with($realFile, $realPublic.DIRECTORY_SEPARATOR) && is_file($realFile)) {
            return $realFile;
        }

        return null;
    }

    /**
     * Checks if the remote avatar URL is safe to fetch: strictly HTTPS, default port, without credentials,
     * and not pointing to localhost, private, or reserved IP networks.
     */
    private function isAllowedRemote(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || $parts['host'] === ''
            || mb_strtolower($parts['host']) === 'localhost'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = mb_trim($parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || IpUtils::checkIp($host, self::BLOCKED_RANGES)) {
                return false;
            }
        }

        return true;
    }

    private function ellipsis(string $text): string
    {
        return mb_rtrim($text, ' ,;:.-').'…';
    }

    /**
     * @return array{left: int, right: int, top: int, bottom: int}
     */
    private function box(string $text, string $font, int $size): array
    {
        $box = imagettfbbox($this->points($size), 0, $this->font($font), $text);

        throw_if($box === false, RuntimeException::class, "Unable to measure text with [{$font}].");

        return [
            'left' => intdiv(Arr::integer($box, 0), self::SCALE),
            'right' => intdiv(Arr::integer($box, 2), self::SCALE),
            'top' => intdiv(Arr::integer($box, 5), self::SCALE),
            'bottom' => intdiv(Arr::integer($box, 1), self::SCALE),
        ];
    }

    private function points(int $pixels): float
    {
        return $pixels * self::SCALE * 0.75;
    }

    private function font(string $name): string
    {
        return resource_path("fonts/{$name}.ttf");
    }

    private function circle(float $x, float $y, float $radius, int $color): void
    {
        $diameter = (int) round($radius * 2 * self::SCALE);

        imagefilledellipse($this->image, (int) round($x * self::SCALE), (int) round($y * self::SCALE), $diameter, $diameter, $color);
    }

    /**
     * Fills a polygon given in the logo's 48 unit grid, cropped to the 45 unit square around the mark like favicon.svg.
     *
     * @param  list<array{float|int, float|int}>  $corners
     */
    private function polygon(array $corners, float $left, float $top, int $size, int $color): void
    {
        $unit = $size / 45;
        $points = [];

        foreach ($corners as [$x, $y]) {
            $points[] = (int) round(($left + ($x - 1.6) * $unit) * self::SCALE);
            $points[] = (int) round(($top + ($y - 1.5) * $unit) * self::SCALE);
        }

        imagefilledpolygon($this->image, $points, $color);
    }

    private function rect(int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        imagefilledrectangle($this->image, $x1 * self::SCALE, $y1 * self::SCALE, $x2 * self::SCALE - 1, $y2 * self::SCALE - 1, $color);
    }

    /**
     * @param  positive-int  $width
     * @param  positive-int  $height
     */
    private function blank(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        assert($image instanceof GdImage);

        imagefill($image, 0, 0, self::SHELL);

        return $image;
    }
}
