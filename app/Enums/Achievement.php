<?php

declare(strict_types=1);

namespace App\Enums;

enum Achievement: string
{
    case Init = 'init';
    case Syntax = 'syntax';
    case Uptime = 'uptime';
    case Daemon = 'daemon';
    case Sysadmin = 'sysadmin';
    case Stdout = 'stdout';
    case KernelSpace = 'kernel_space';
    case Stargazer = 'stargazer';
    case Superstar = 'superstar';
    case Pipe = 'pipe';
    case AuthorizedKeys = 'authorized_keys';
    case Pastelaria = 'pastelaria';

    public function label(): string
    {
        return match ($this) {
            self::Init => 'init',
            self::Syntax => 'syntax',
            self::Uptime => 'uptime',
            self::Daemon => 'daemon',
            self::Sysadmin => 'sysadmin',
            self::Stdout => 'stdout',
            self::KernelSpace => 'kernel space',
            self::Stargazer => 'stargazer',
            self::Superstar => 'superstar',
            self::Pipe => 'pipe',
            self::AuthorizedKeys => 'authorized_keys',
            self::Pastelaria => 'pastelaria',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Init => 'Piped your first commit to /dev/null.',
            self::Syntax => 'Committed formatted code blocks in markdown.',
            self::Uptime => 'Maintained a 7-day commit streak without a reboot.',
            self::Daemon => 'Maintained a 30-day commit streak.',
            self::Sysadmin => 'Maintained a 100-day commit streak.',
            self::Stdout => 'Reached 50 commits piped to /dev/null.',
            self::KernelSpace => 'Reached 100 commits piped to /dev/null.',
            self::Stargazer => 'Starred 10 commits by other developers.',
            self::Superstar => 'Received 25 stars on your commits.',
            self::Pipe => 'Joined conversations with 5 replies to other developers.',
            self::AuthorizedKeys => 'Provisioned a public SSH key from GitHub.',
            self::Pastelaria => 'Received 10 pastéis de nata on your commits.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Init => 'terminal',
            self::Syntax => 'file-code',
            self::Uptime => 'flame',
            self::Daemon => 'zap',
            self::Sysadmin => 'server',
            self::Stdout => 'box',
            self::KernelSpace => 'shield',
            self::Stargazer => 'star',
            self::Superstar => 'sparkles',
            self::Pipe => 'git-merge',
            self::AuthorizedKeys => 'key',
            self::Pastelaria => 'croissant',
        };
    }

    public function target(): int
    {
        return match ($this) {
            self::Init => 1,
            self::Syntax => 1,
            self::Uptime => 7,
            self::Daemon => 30,
            self::Sysadmin => 100,
            self::Stdout => 50,
            self::KernelSpace => 100,
            self::Stargazer => 10,
            self::Superstar => 25,
            self::Pipe => 5,
            self::AuthorizedKeys => 1,
            self::Pastelaria => 10,
        };
    }
}
