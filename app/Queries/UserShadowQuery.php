<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\UnixRank;
use App\Models\Shadow;
use App\Models\User;

final readonly class UserShadowQuery
{
    public function __construct(private UserAchievementsQuery $achievements) {}

    /**
     * @return array{
     *     name: string,
     *     glyph: string,
     *     skin: string,
     *     title: string,
     *     unlocked_skins: list<string>,
     *     rank: string,
     *     level: int,
     *     aura_color: string
     * }
     */
    public function get(User $user, ?User $viewer): array
    {
        /** @var Shadow|null $shadow */
        $shadow = Shadow::query()->where('user_id', $user->id)->first();

        $achievementData = $this->achievements->get($user, $viewer);

        $auraColor = UnixRank::from($achievementData['rank'])->auraColor();

        if ($shadow instanceof Shadow) {
            return [
                'name' => $shadow->name,
                'glyph' => $shadow->glyph,
                'skin' => $shadow->skin,
                'title' => $shadow->title ?? "PID {$achievementData['level']}024",
                'unlocked_skins' => $shadow->unlocked_skins,
                'rank' => $achievementData['rank'],
                'level' => $achievementData['level'],
                'aura_color' => $auraColor,
            ];
        }

        return [
            'name' => 'daemon-0',
            'glyph' => 'λ',
            'skin' => 'classic-green',
            'title' => "PID {$achievementData['level']}024",
            'unlocked_skins' => ['classic-green'],
            'rank' => $achievementData['rank'],
            'level' => $achievementData['level'],
            'aura_color' => $auraColor,
        ];
    }
}
