<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CreateCommitImages;
use App\Dto\Giphy;
use App\Dto\NewPoll;
use App\Enums\PollDuration;
use App\Models\Commit;
use App\Models\User;
use App\Rules\GiphyMediaUrl;
use App\Rules\VisibleCommit;
use App\Services\CommitBodies;
use App\Services\Urls;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;

final class CreateCommitRequest extends FormRequest
{
    public const array BODY_RULES = ['required', 'string', 'min:'.Commit::MIN_BODY_LENGTH, 'max:'.Commit::MAX_BODY_LENGTH];

    /**
     * Images say enough on their own, so a commit with images may have a body of any length, or none at all.
     */
    public const array BODY_WITH_IMAGES_RULES = ['nullable', 'string', 'max:'.Commit::MAX_BODY_LENGTH];

    /**
     * Blank lines are collapsed before the body is measured, by every request that takes a body with BODY_RULES.
     */
    public static function normalizeBody(FormRequest $request): void
    {
        if (is_string($request->input('body'))) {
            $request->merge(['body' => resolve(CommitBodies::class)->normalize($request->string('body')->value())]);
        }
    }

    /**
     * Images are checked by their contents, not their extension, and SVG is never accepted since it can carry scripts.
     *
     * @return array<string, array<int, string|ValidationRule|File|Dimensions|Enum>>
     */
    public static function attachmentRules(): array
    {
        return [
            'poll' => ['nullable', 'array:options,duration', 'prohibits:parent_id,images'],
            'poll.options' => ['required_with:poll', 'list', 'min:'.NewPoll::MinOptions, 'max:'.NewPoll::MaxOptions],
            'poll.options.*' => ['required', 'string', 'max:'.NewPoll::MaxOptionLength, 'distinct:ignore_case'],
            'poll.duration' => ['required_with:poll', Rule::enum(PollDuration::class)],
            'images' => ['nullable', 'list', 'max:'.CreateCommitImages::MAX_IMAGES],
            'images.*' => [
                'required',
                'image',
                File::types(['image/jpeg', 'image/png', 'image/webp'])->max(CreateCommitImages::MAX_UPLOAD_SIZE),
                Rule::dimensions()
                    ->maxWidth(CreateCommitImages::MAX_SOURCE_DIMENSION)
                    ->maxHeight(CreateCommitImages::MAX_SOURCE_DIMENSION),
            ],
            'image_descriptions' => ['nullable', 'list', 'max:'.CreateCommitImages::MAX_IMAGES],
            'image_descriptions.*' => ['nullable', 'string', 'max:'.CreateCommitImages::MAX_DESCRIPTION_LENGTH],
            'giphy_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/', 'prohibits:images,poll', 'required_with:giphy_url'],
            'giphy_url' => ['nullable', 'string', 'max:2048', 'required_with:giphy_id', new GiphyMediaUrl],
            'giphy_page_url' => ['nullable', 'string', 'max:2048', 'required_with:giphy_id', 'url:https', 'regex:#^https://(www\.)?giphy\.com/#'],
            'giphy_title' => ['nullable', 'string', 'max:300'],
            'giphy_username' => ['nullable', 'string', 'max:100'],
            'giphy_width' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_with:giphy_id'],
            'giphy_height' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_with:giphy_id'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ValidationRule|File|Dimensions|Enum|In>>
     */
    public function rules(): array
    {
        $user = $this->user();
        assert($user instanceof User);
        $body = $this->input('body');

        return [
            'body' => $this->hasFile('images') ? self::BODY_WITH_IMAGES_RULES : self::BODY_RULES,
            'quoted_commit_id' => ['nullable', 'uuid', 'prohibits:parent_id', new VisibleCommit($user, __('That commit is no longer available to quote.'))],
            'link_preview_url' => ['nullable', 'string', Rule::in(is_string($body) ? resolve(Urls::class)->all($body) : [])],
            ...self::attachmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'image_descriptions.*' => 'image description',
        ];
    }

    public function poll(): ?NewPoll
    {
        if (! $this->filled('poll')) {
            return null;
        }

        /** @var list<string> $options */
        $options = $this->array('poll.options');

        return new NewPoll($options, PollDuration::from($this->integer('poll.duration')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'link_preview_url.in' => __('The link card must be one of the links in the body.'),
        ];
    }

    /**
     * The first link in the body gets the link preview, unless the author chose another link or none at all.
     */
    public function linkPreview(): bool|string
    {
        if (! $this->has('link_preview_url')) {
            return true;
        }

        return $this->filled('link_preview_url') ? $this->string('link_preview_url')->value() : false;
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $images = $this->file('images');

        return is_array($images) ? array_values($images) : [];
    }

    /**
     * @return list<string|null>
     */
    public function imageDescriptions(): array
    {
        /** @var list<string|null> $descriptions */
        $descriptions = $this->array('image_descriptions');

        return $descriptions;
    }

    public function quotedCommit(): ?Commit
    {
        $user = $this->user();
        assert($user instanceof User);

        return $this->filled('quoted_commit_id')
            ? Commit::query()->visibleTo($user)->find($this->string('quoted_commit_id')->value())
            : null;
    }

    public function giphy(): ?Giphy
    {
        if (! $this->filled('giphy_id')) {
            return null;
        }

        return new Giphy(
            $this->string('giphy_id')->value(),
            $this->string('giphy_url')->value(),
            $this->string('giphy_page_url')->value(),
            $this->filled('giphy_title') ? $this->string('giphy_title')->value() : null,
            $this->filled('giphy_username') ? $this->string('giphy_username')->value() : null,
            $this->integer('giphy_width'),
            $this->integer('giphy_height'),
        );
    }

    public function prepareForValidation(): void
    {
        self::normalizeBody($this);
    }
}
