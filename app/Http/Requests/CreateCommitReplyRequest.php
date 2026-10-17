<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\CreateCommitImages;
use App\Dto\Giphy;
use App\Models\Commit;
use App\Models\User;
use App\Rules\GiphyMediaUrl;
use App\Services\Urls;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;

final class CreateCommitReplyRequest extends FormRequest
{
    public function authorize(): Response
    {
        $user = $this->user();
        assert($user instanceof User);

        $commit = $this->route('commit');
        assert($commit instanceof Commit);

        return $commit->isVisibleTo($user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function prepareForValidation(): void
    {
        CreateCommitRequest::normalizeBody($this);
    }

    /**
     * Images are checked by their contents, not their extension, and SVG is never accepted since it can carry scripts.
     *
     * @return array<string, array<int, string|ValidationRule|File|Dimensions|In>>
     */
    public function rules(): array
    {
        $body = $this->input('body');

        return [
            'body' => $this->hasFile('images') ? CreateCommitRequest::BODY_WITH_IMAGES_RULES : CreateCommitRequest::BODY_RULES,
            'link_preview_url' => ['nullable', 'string', Rule::in(is_string($body) ? resolve(Urls::class)->all($body) : [])],
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
            'giphy_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/', 'prohibits:images', 'required_with:giphy_url'],
            'giphy_url' => ['nullable', 'string', 'max:2048', 'required_with:giphy_id', new GiphyMediaUrl],
            'giphy_page_url' => ['nullable', 'string', 'max:2048', 'required_with:giphy_id', 'url:https', 'regex:#^https://(www\.)?giphy\.com/#'],
            'giphy_title' => ['nullable', 'string', 'max:300'],
            'giphy_username' => ['nullable', 'string', 'max:100'],
            'giphy_width' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_with:giphy_id'],
            'giphy_height' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_with:giphy_id'],
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
}
