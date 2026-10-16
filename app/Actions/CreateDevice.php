<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AuthorizationCode;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

final readonly class CreateDevice
{
    /**
     * Redeems an authorization code: the key it was handed out for becomes an approved device of the user who approved
     * it, and the code is used up. A key that was approved before, even for someone else, is approved again for them.
     *
     * The code is locked while it is redeemed, so of two requests with the same code only one gets a device. The other
     * gets null, as does a code that expired since it was checked.
     */
    public function handle(AuthorizationCode $authorizationCode): ?Device
    {
        return DB::transaction(function () use ($authorizationCode): ?Device {
            $code = AuthorizationCode::query()
                ->whereKey($authorizationCode->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $code instanceof AuthorizationCode) {
                return null;
            }

            $code->delete();

            return Device::query()->updateOrCreate(['fingerprint' => $code->fingerprint], [
                'user_id' => $code->user_id,
                'name' => $code->device_name,
                'approved_at' => now(),
                'last_used_at' => null,
            ]);
        });
    }
}
