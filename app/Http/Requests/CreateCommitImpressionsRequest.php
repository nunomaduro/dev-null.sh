<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CreateCommitImpressions;
use App\Services\CommitImpressionToken;
use Illuminate\Foundation\Http\FormRequest;

final class CreateCommitImpressionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'uuid'],
            'impressions' => ['required', 'list', 'min:1', 'max:'.CreateCommitImpressions::MAX_BATCH_SIZE],
            'impressions.*' => ['required', 'array:commit_id,token'],
            'impressions.*.commit_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'impressions.*.token' => ['required', 'string', 'max:'.CommitImpressionToken::MAX_LENGTH],
        ];
    }

    public function batchId(): string
    {
        /** @var string $batchId */
        $batchId = $this->validated('batch_id');

        return $batchId;
    }

    /**
     * @return list<array{commit_id: string, token: string}>
     */
    public function impressions(): array
    {
        /** @var list<array{commit_id: string, token: string}> $impressions */
        $impressions = $this->validated('impressions');

        return $impressions;
    }
}
