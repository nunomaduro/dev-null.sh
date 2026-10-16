<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\EasterEgg;

final readonly class EasterEggs
{
    public function __construct(
        private HelloWorld $helloWorld,
    ) {
        //
    }

    public function find(string $body): ?EasterEgg
    {
        return match (true) {
            $this->helloWorld->matches($body) => new EasterEgg('world', 'I answer to hello world.', ['Hello, :name.']),
            mb_trim($body, " \t\n\r\0\x0B`") === 'php artisan migrate:fresh' => new EasterEgg('artisan', 'I run your commands.', ['Deleting database.', 'Just kidding.']),
            default => null,
        };
    }
}
