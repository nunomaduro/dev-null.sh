<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

final class ShowApiCommitRequest extends FormRequest
{
    /**
     * Anyone may see an approved commit. A signed request may also see its user's own pending or rejected commits.
     */
    public function authorize(): Response
    {
        $commit = $this->route('commit');
        assert($commit instanceof Commit);

        return $commit->isVisibleTo($this->user()) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * @return array{}
     */
    public function rules(): array
    {
        return [];
    }
}
