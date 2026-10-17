<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeBaseSection;
use App\Services\OpenAI;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBaseSection>
 */
final class KnowledgeBaseSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => fake()->unique()->slug().'.md',
            'position' => 0,
            'headings' => ['Knowledge base', 'Lore'],
            'content' => fake()->paragraph(),
            'embedding' => [1.0, ...array_fill(0, OpenAI::EMBEDDING_DIMENSIONS - 1, 0.0)],
        ];
    }
}
