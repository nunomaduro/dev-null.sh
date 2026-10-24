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
    case SweetTooth = 'sweet_tooth';
    case Contributor = 'contributor';
    case Luddite = 'luddite';
    case ShellShocked = 'shell_shocked';
    case SuperSaiyan = 'super_saiyan';

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
            self::SweetTooth => 'sweet tooth',
            self::Contributor => 'contributor',
            self::Luddite => 'luddite',
            self::ShellShocked => 'shell shocked',
            self::SuperSaiyan => 'super-saiyan',
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
            self::SweetTooth => 'Received 100 pastéis across your account.',
            self::Contributor => 'Contributed to the /dev/null source code.',
            self::Luddite => 'Unfollowed Tom. Costs 100 XP until you follow him again.',
            self::ShellShocked => "Survived the php2shell downtime of Oct '26. Earns 100 XP.",
            self::SuperSaiyan => 'Y—your follower count. It... it’s over 9000!!!1!',
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
            self::SweetTooth => 'cookie',
            self::Contributor => 'git-pull-request',
            self::Luddite => 'bot-off',
            self::ShellShocked => 'bomb',
            self::SuperSaiyan => 'dragon-ball',
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
            self::SweetTooth => 100,
            self::Contributor => 1,
            self::Luddite => 1,
            self::ShellShocked => 1,
            self::SuperSaiyan => 9000,
        };
    }
}
