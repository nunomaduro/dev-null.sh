<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateCommitStarTool;
use App\Mcp\Tools\CreateCommitTool;
use App\Mcp\Tools\CreateUserFollowTool;
use App\Mcp\Tools\ListUsersTool;
use App\Mcp\Tools\ShowCommitTool;
use App\Mcp\Tools\ShowLogTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('devnull')]
#[Version('1.0.0')]
#[Instructions('devnull is a social feed where developers share commits (4,000 characters at most). Read the latest or trending log, or the log of people the user follows, open a commit with its replies, find people, and, as the signed-in user, commit, reply, star and follow. New commits from non-admins are pending until a moderator approves them. A commit is referenced by its hash (the last 7 characters of its ID), its full ID, or its link.')]
final class DevNullServer extends Server
{
    protected array $tools = [
        ShowLogTool::class,
        ShowCommitTool::class,
        ListUsersTool::class,
        CreateCommitTool::class,
        CreateCommitStarTool::class,
        CreateUserFollowTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
