<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Services\GitHubActivity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

final class ShowCommitGitHubActivityRequest extends FormRequest
{
    /**
     * Anyone who can see an /activity commit sees its author's GitHub activity. Any other commit has none.
     */
    public function authorize(GitHubActivity $githubActivity): Response
    {
        $commit = $this->route('commit');
        assert($commit instanceof Commit);

        return $commit->isVisibleTo($this->user()) && $githubActivity->matches($commit->body)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
