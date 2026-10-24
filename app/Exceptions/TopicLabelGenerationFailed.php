<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TopicLabelGenerationFailed extends RuntimeException
{
    public function __construct(Throwable $exception)
    {
        parent::__construct(
            'Topic label generation failed ('.$exception::class.', code '.$exception->getCode().').',
            (int) $exception->getCode(),
        );
    }
}
