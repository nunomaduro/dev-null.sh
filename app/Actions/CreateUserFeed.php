<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Support\Str;
use XMLWriter;

final readonly class CreateUserFeed
{
    public const int LIMIT = 50;

    /**
     * Renders the user's latest public top-level commits as an Atom feed.
     */
    public function handle(User $user): string
    {
        $commits = $user->commits()
            ->approved()
            ->topLevel()
            ->with('images')
            ->latest()
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs(null, 'feed', 'http://www.w3.org/2005/Atom');

        $xml->writeElement('id', "urn:uuid:{$user->id}");
        $xml->writeElement('title', $this->text("{$user->nameWithHandle()} on /dev/null"));

        if ($user->bio !== null) {
            $xml->writeElement('subtitle', $this->text($user->bio));
        }

        $lastUpdatedAt = $commits->first()->created_at ?? $user->created_at;
        $xml->writeElement('updated', $lastUpdatedAt->max($user->updated_at)->toAtomString());
        $this->link($xml, 'self', route('user-feed.show', $user), 'application/atom+xml');
        $this->link($xml, 'alternate', route('user.show', $user), 'text/html');

        $xml->startElement('author');
        $xml->writeElement('name', $this->text($user->name));
        $xml->writeElement('uri', route('user.show', $user));
        $xml->endElement();

        foreach ($commits as $commit) {
            $this->entry($xml, $user, $commit);
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function entry(XMLWriter $xml, User $user, Commit $commit): void
    {
        $xml->startElement('entry');
        $xml->writeElement('id', "urn:uuid:{$commit->id}");
        $xml->writeElement('title', $this->text(Str::limit(Str::squish($commit->body), 72, '…', preserveWords: true)));
        $this->link($xml, 'alternate', route('commit.show', [$user, $commit]), 'text/html');

        foreach ($commit->images as $image) {
            $this->link($xml, 'enclosure', $image->url(), 'image/webp');
        }

        $xml->writeElement('published', $commit->created_at->toAtomString());
        $xml->writeElement('updated', $commit->created_at->toAtomString());

        $xml->startElement('content');
        $xml->writeAttribute('type', 'text');
        $xml->text($this->text($commit->body));
        $xml->endElement();

        $xml->endElement();
    }

    private function link(XMLWriter $xml, string $rel, string $href, string $type): void
    {
        $xml->startElement('link');
        $xml->writeAttribute('rel', $rel);
        $xml->writeAttribute('type', $type);
        $xml->writeAttribute('href', $href);
        $xml->endElement();
    }

    /**
     * XML 1.0 cannot carry most control characters, even escaped, so they are dropped.
     */
    private function text(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }
}
