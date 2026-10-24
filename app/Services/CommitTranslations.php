<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommitStatus;
use App\Enums\Feature;
use App\Exceptions\CommitProcessingFailed;
use App\Jobs\DetectCommitLanguage;
use App\Models\Commit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Locale;
use ResourceBundle;
use Throwable;

/**
 * @phpstan-type CachedTranslations array{source_language: string, translations: array<string, string>, expires_at: int}
 */
final readonly class CommitTranslations
{
    public const int TTL_SECONDS = 604800;

    public function __construct(private Features $features)
    {
        //
    }

    public function enabled(): bool
    {
        return $this->features->enabled(Feature::Translations);
    }

    public function key(Commit $commit): string
    {
        return 'commit-translations:v1:'.hash('sha256', $commit->body);
    }

    /** @return list<string> */
    public function languages(): array
    {
        return once(function (): array {
            /** @var list<string> $locales */
            $locales = ResourceBundle::getLocales('');

            return array_values(collect($locales)->map(fn (string $locale): string => Locale::getPrimaryLanguage($locale) ?? 'und')->unique()->all());
        });
    }

    public function language(): string
    {
        $language = Locale::getPrimaryLanguage(request()->user()->translation_language ?? request()->getPreferredLanguage() ?? 'en');

        return in_array($language, $this->languages(), true) ? $language : 'en';
    }

    /** @return CachedTranslations|null */
    public function cached(Commit $commit): ?array
    {
        /** @var CachedTranslations|null $cached */
        $cached = Cache::get($this->key($commit));

        return $cached;
    }

    /** @param Collection<int, Commit> $commits */
    public function attach(Collection $commits, bool $includePending = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        Cache::memo()->many($commits->map($this->key(...))->all());

        foreach ($commits as $commit) {
            $commit->setAttribute('translation', $this->display($commit, includePending: $includePending));
        }
    }

    /** @return array<string, mixed>|null */
    public function display(Commit $commit, ?string $language = null, bool $includePending = false): ?array
    {
        if ($commit->trashed() || ! in_array($commit->status, $includePending ? [CommitStatus::Approved, CommitStatus::Pending] : [CommitStatus::Approved], true) || $commit->body === '' || ! $this->enabled()) {
            return null;
        }

        /** @var CachedTranslations|null $cached */
        $cached = Cache::memo()->get($this->key($commit));

        if ($cached === null) {
            try {
                dispatch(new DetectCommitLanguage($commit->id))->afterCommit();
            } catch (Throwable $exception) {
                report(new CommitProcessingFailed('Language detection dispatch', $commit->id, $exception));
            }
        }

        $language ??= $this->language();
        $source = $cached['source_language'] ?? null;
        $body = $cached['translations'][$language] ?? null;

        return [
            'language' => $language,
            'source_language' => $source,
            'source_name' => $source === null ? null : Locale::getDisplayLanguage($source, 'en'),
            'available' => $source !== $language && $source !== 'und' && ($source !== null || $commit->user_id !== request()->user()?->id),
            'translated' => $body === null ? null : resolve(CommitBodies::class)->segments($body),
        ];
    }
}
