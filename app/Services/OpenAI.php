<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\BooleanAnswer;

final readonly class OpenAI
{
    /**
     * Far stricter than a coin flip: any category this likely is flagged.
     */
    private const float STRICT_THRESHOLD = 0.2;

    /**
     * @var array<string, float>
     */
    private const array THRESHOLDS = [
        'sexual/minors' => 0.01,
    ];

    /**
     * @var array<string, string>
     */
    private const array CATEGORIES = [
        'harassment' => 'Content that expresses, incites or promotes harassing language towards any target.',
        'harassment/threatening' => 'Harassment that also includes violence or serious harm towards any target.',
        'hate' => 'Content that expresses, incites or promotes hate based on race, gender, ethnicity, religion, nationality, sexual orientation, disability or caste.',
        'hate/threatening' => 'Hateful content that also includes violence or serious harm towards the targeted group.',
        'illicit' => 'Content that gives advice or instructions for committing wrongdoing, such as how to shoplift or build a weapon.',
        'self-harm' => 'Content that promotes, encourages or depicts acts of self-harm, such as suicide, cutting and eating disorders.',
        'sexual' => 'Content meant to arouse sexual excitement, or that promotes sexual services.',
        'sexual/minors' => 'Sexual content that includes an individual who is under 18 years old.',
        'violence' => 'Content that depicts death, violence or physical injury.',
        'violence/graphic' => 'Content that depicts death, violence or physical injury in graphic detail.',
    ];

    private const string MINORS_INSTRUCTIONS = 'Is any person in the images, or could any plausibly be, under 18 years old? That includes babies, children and teenagers, whether in photos, drawings, renders or any other depiction. When unsure, answer true.';

    /**
     * The categories the content is flagged for, if any: those scoring above our strict thresholds. The images go
     * along with the text, so they are judged together.
     *
     * @param  list<string>  $imageDataUrls
     * @return list<string>
     */
    public function moderate(string $text, array $imageDataUrls = []): array
    {
        $questions = [];

        foreach (self::CATEGORIES as $category => $description) {
            $questions[$category] = new Boolean($description);
        }

        $answers = Classification::of(
            $text === '' ? 'No text, only images.' : $text,
            array_map(self::image(...), $imageDataUrls),
        )
            ->questions($questions)
            ->timeout(60)
            ->classify(provider: 'openai');

        $categories = [];

        foreach (array_keys(self::CATEGORIES) as $category) {
            $answer = $answers->answer($category);
            assert($answer instanceof BooleanAnswer);

            if ($answer->isTrue(self::THRESHOLDS[$category] ?? self::STRICT_THRESHOLD)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * Whether a minor may appear in any of the images. Anything but a clear no counts as a yes.
     *
     * @param  list<string>  $imageDataUrls
     */
    public function showsMinors(array $imageDataUrls): bool
    {
        $answer = Classification::of('Images uploaded to devnull, a social network for developers.', array_map(self::image(...), $imageDataUrls))
            ->question('minors', new Boolean(self::MINORS_INSTRUCTIONS))
            ->timeout(60)
            ->classify(provider: 'openai')
            ->answer('minors');
        assert($answer instanceof BooleanAnswer);

        return $answer->isTrue(self::STRICT_THRESHOLD);
    }

    private static function image(string $imageDataUrl): Image
    {
        [$header, $base64] = explode(',', $imageDataUrl, 2);

        return Image::fromBase64($base64, mb_substr($header, 5, (int) mb_strpos($header, ';') - 5));
    }
}
