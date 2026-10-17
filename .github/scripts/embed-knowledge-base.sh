#!/usr/bin/env bash
set -euo pipefail

: "${LARAVEL_CLOUD_TOKEN:?Set the LARAVEL_CLOUD_TOKEN GitHub Actions secret}"
: "${LARAVEL_CLOUD_ENVIRONMENT_ID:?Set the LARAVEL_CLOUD_ENVIRONMENT_ID GitHub Actions variable}"
: "${GITHUB_SHA:?Missing triggering commit}"
: "${GITHUB_REPOSITORY:?Missing repository}"

deadline=$((SECONDS + 1200))

while (( SECONDS < deadline )); do
    environment="$(cloud environment:get "$LARAVEL_CLOUD_ENVIRONMENT_ID" --json -n \
        --fields=branch,application.repositoryFullName,currentDeploymentId)"

    if ! jq -e --arg repository "$GITHUB_REPOSITORY" \
        '.branch == "production" and .application.repositoryFullName == $repository' \
        <<< "$environment" > /dev/null; then
        echo 'The Cloud environment must deploy this repository’s production branch.' >&2
        exit 1
    fi

    deployment_id="$(jq -r '.currentDeploymentId // empty' <<< "$environment")"

    if [[ -n "$deployment_id" ]]; then
        deployment="$(cloud deployment:get "$deployment_id" --json -n --fields=status,commitHash)"
        deployed_sha="$(jq -r '.commitHash // empty' <<< "$deployment")"

        if [[ "$(jq -r '.status' <<< "$deployment")" == 'deployment.succeeded' && "$deployed_sha" =~ ^[0-9a-f]{40}$ ]]; then
            # A later production push may supersede this one while Cloud is building.
            git fetch --quiet --no-tags origin production

            if git merge-base --is-ancestor "$GITHUB_SHA" "$deployed_sha"; then
                expected_hash="$(git show "$deployed_sha:KNOWLEDGE_BASE.md" | sha256sum | cut -d ' ' -f 1)"

                # Check the file again in the command's actual release, closing the deployment race.
                cloud command:run "$LARAVEL_CLOUD_ENVIRONMENT_ID" \
                    --cmd="php artisan knowledge-base:embed --expected-hash=$expected_hash --no-interaction" \
                    --json -n --fields=id,status,exitCode,output
                exit 0
            fi
        fi
    fi

    echo 'Waiting for the merged commit (or a newer production commit) to be live...'
    sleep 10
done

echo 'Timed out waiting for a successful production release. No embedding command was started.' >&2
exit 1
