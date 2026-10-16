<?php

declare(strict_types=1);

use App\Enums\CommitGift;

it('provides labels, icons, costs, and descriptions for all commit gifts', function (CommitGift $gift): void {
    expect($gift->label())->toBeString()->not->toBeEmpty();
    expect($gift->icon())->toBeString()->not->toBeEmpty();
    expect($gift->cost())->toBeGreaterThan(0);
    expect($gift->description())->toBeString()->not->toBeEmpty();
})->with(CommitGift::cases());
