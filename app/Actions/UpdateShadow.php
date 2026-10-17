<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Shadow;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UpdateShadow
{
    /**
     * Updates the user's Shadow companion cosmetics and attributes.
     */
    public function handle(User $user, ?string $name = null, ?string $glyph = null, ?string $skin = null): Shadow
    {
        /** @var Shadow $shadow */
        $shadow = Shadow::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'daemon-0',
                'glyph' => 'λ',
                'skin' => 'classic-green',
                'unlocked_skins' => ['classic-green'],
            ]
        );

        if ($name !== null && $name !== '') {
            $shadow->name = Str::limit($name, 32, '');
        }

        if ($glyph !== null && $glyph !== '') {
            $shadow->glyph = mb_substr($glyph, 0, 16);
        }

        if ($skin !== null && $skin !== '') {
            match (in_array($skin, $shadow->unlocked_skins, true)) {
                false => throw new InvalidArgumentException("Skin '{$skin}' has not been unlocked yet."),
                true => null,
            };

            $shadow->skin = $skin;
        }

        $shadow->save();

        return $shadow;
    }
}
