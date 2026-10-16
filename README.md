# Project setup

A social network for developers. Sign in with GitHub, follow developers, and share commits.

## Getting Started

Requires PHP 8.5+, Composer, Bun, and PostgreSQL 17+ with the [pgvector](https://github.com/pgvector/pgvector) and `pg_trgm` extensions available. [Laravel Herd](https://herd.laravel.com)'s PostgreSQL service ships with both. Create a `devnull` database for the app and a `testing` database for the test suite.

```bash
git clone https://github.com/nunomaduro/dev-null.sh.git
cd dev-null.sh
composer setup
```

The setup script installs dependencies, creates `.env`, generates an app key, runs migrations, and builds assets.

### Local auto-login

To sign in locally without GitHub, set the following in `.env`:

```dotenv
APP_ENV=local
AUTO_LOGIN=true
```

Run `composer dev` and visit `http://localhost:8000` (or your configured `APP_URL`). Auto-login only works in the local environment.

### GitHub login

Leave `AUTO_LOGIN=false` to sign in using GitHub. Create a [GitHub OAuth app](https://github.com/settings/developers) with the callback URL `{APP_URL}/auth/github/callback`, then set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`.

### Embeds

A link card for a video, song or pen becomes a player that loads when clicked. The player URL is built on the server from the link, so only the sites below can be framed. None of them need an API key.

| Key | Links |
|---|---|
| `youtube` | `youtube.com/watch`, `youtu.be`, `/shorts`, `/live`, `/playlist` (with `t=` start times) |
| `vimeo` | `vimeo.com/{id}`, unlisted `vimeo.com/{id}/{hash}`, channels and groups |
| `loom` | `loom.com/share/{id}` |
| `twitch` | channels, `/videos/{id}` and clips |
| `suno` | `suno.com/song/{id}` |
| `spotify` | tracks, albums, playlists, artists, episodes and shows |
| `soundcloud` | tracks and sets |
| `apple_music` | albums, playlists and songs |
| `codepen` | pens |
| `asciinema` | recordings |

| Variable | Default | |
|---|---|---|
| `EMBEDS_ENABLED` | `true` | `false` turns every link back into a plain link card |
| `EMBEDS_PROVIDERS` | empty (all) | Comma-separated keys from the table above, such as `youtube,suno,spotify` |
| `EMBEDS_YOUTUBE_PRIVACY_ENHANCED` | `true` | Plays YouTube from `youtube-nocookie.com` |

Twitch requires the embedding site's host, which is taken from `APP_URL`. To add a site, implement `App\Contracts\Services\EmbedProvider` and register it in `config/embeds.php`.

### Verify installation

Run `composer test` to check linting, static analysis, type coverage, and tests. The code coverage check requires Xdebug or PCOV.

## Public API

Read-only, no authentication, JSON, versioned under `/api/v1`. Only approved commits are returned; pending, rejected, and deleted commits are a 404 or left out. Rate limited to 60 requests a minute per IP, and open to cross-origin `GET` requests.

| Endpoint | Returns |
|---|---|
| `GET /api/v1/users/{username}` | A public profile with commit, follower, and following counts |
| `GET /api/v1/users/{username}/commits` | The user's top-level commits, newest first, 20 per page (`?replies=1` for their replies instead) |
| `GET /api/v1/commits/{id}` | One commit with its author, public parent id, star and reply counts, link preview (with its `embed` player, if any), and images (`id`, `url`, `width`, `height`) |

Lists are cursor-paginated: follow `links.next` (or pass `meta.next_cursor` as `?cursor=`) until it is `null`.

```bash
curl -s https://dev-null.sh/api/v1/users/nunomaduro/commits | jq '.data[] | {short_hash, body}'
```

Every profile also has an Atom feed of its latest 50 top-level commits at `/@{username}/feed.atom`, with their images as enclosures, cached for five minutes.

## Signed requests

The mobile app signs its requests to `/api/v1` with an SSH key it keeps on the device, which the user approved on the web (see [Signing in](#signing-in)). A signed request to the endpoints above gets what the web shows that user. The log, replies, notifications, committing, deleting and following only take signed requests. Signed requests are limited to 120 a minute per user.

`GET /api/v1/log/{log}` serves the web's three logs, `following`, `latest` and `trending`, with the same queries and 20 commits a page. Trending is ranked, so it also says `is_ranked` and the `newest_commit_id` when it was loaded. With `?since={id}`, every log counts the `new_commits_count` newer than that commit, and Trending also returns them as `latest_commits`, the way the web offers them above it. An empty Following log comes with a few `suggestions`.

Each request sends three headers:

| Header | Value |
|---|---|
| `X-Devnull-Timestamp` | Unix time in seconds, within 5 minutes of the server's clock |
| `X-Devnull-Nonce` | 32 random hex characters, used once |
| `X-SSH-Signature` | The SSHSIG signature, base64 on one line |

The signature is made with `ssh-keygen -Y sign -n devnull-api` over these lines, joined by `\n`: the timestamp, the nonce, the method, the path with its query string exactly as sent, and the raw body.

```text
1760534400
3f9c2a7e5b1d4c8f9a0b2c3d4e5f6a7b
POST
/api/v1/commits
{"body":"Hello from my phone"}
```

A request with any of these headers has to be signed right. A bad or expired signature is a `401`, never the public view. A request without them always gets the public view, and across a block a signed request gets neither profile: it is a `403`, `user_blocked` for the one who blocked and `user_unavailable` for the other, and their commits are hidden as on the web.

A key signs in once its user approved it as a device. It doesn't have to be on their GitHub account, and being on it isn't enough, so a key made for the GitHub Action can't use the API. Users see their devices, when each was last used, and revoke them under Settings → Devices (`/settings/devices`). A key that isn't approved, or was revoked, gets a `401` with `device_not_approved`, and the app signs in again.

### Errors

An error is JSON with a `message` to show the user. The errors the app has to tell apart also have a `code`, which stays the same when the message changes:

```json
{"message": "This key isn't approved for the /dev/null app. Sign in again in the app.", "code": "device_not_approved"}
```

| Status | `code` | When |
|---|---|---|
| `400` | `invalid_grant` | `POST /api/v1/devices` only: the code is wrong, expired or used, was handed out for another key, or the verifier doesn't match. |
| `401` | `invalid_signature` | The signature is missing, malformed or wrong, has expired, or was used already. |
| `401` | `device_not_approved` | Nobody approved the key as a device, or it was revoked. |
| `403` | `user_blocked` | The user blocked the profile they asked for. Neither of them sees the other's commits. |
| `403` | `user_unavailable` | The profile isn't available to the user, because it blocked them. |
| `429` | `rate_limited` | Over 120 signed requests a minute for the user, 10 commits a minute, or 30 follows and unfollows a minute. `Retry-After` says when to try again. |

Other errors have no `code`, like a `422` for a commit that fails validation or a `404` for one that doesn't exist. Unsigned requests to the public endpoints get the same errors as before, without codes.

### Signing in

The app signs in the way an OAuth app does, with the authorization code flow and PKCE ([RFC 7636](https://www.rfc-editor.org/rfc/rfc7636)), and /dev/null as the provider. The code it gets is bound to its key, so only the app that holds the key can redeem it.

1. The app makes an ed25519 key and a random `code_verifier`, and opens this page in a browser session (`ASWebAuthenticationSession` on iOS):

   ```text
   https://dev-null.sh/mobile/authorize
       ?fingerprint=SHA256:hPEdU4FVZ1lfjzbPyBhqTWG2qxUu3ZbCnD8mZw9t2Vk
       &device_name=devnull%20on%20iPhone
       &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
       &code_challenge_method=S256
       &state=af0ifjsldkj
       &redirect_uri=devnull://auth/callback
   ```

   `code_challenge` is `base64url(sha256(code_verifier))` without padding. `redirect_uri` has to start with `devnull://`. A guest signs in with GitHub first and comes back to the page. A request with a missing or bad parameter gets an error page and is never redirected.
2. The page asks "Approve devnull on iPhone?", with the key's fingerprint and the account it signs in as. **Approve** redirects (`303`) to `devnull://auth/callback?code=…&state=af0ifjsldkj`. **Cancel** goes to `devnull://auth/callback?error=access_denied&state=af0ifjsldkj`. The app checks that `state` is the one it sent. A code is good for 5 minutes and only once, and each user can approve 10 times a minute.
3. The app redeems the code with `POST /api/v1/devices`, signed with the key like any other request, though the key isn't approved yet:

   ```json
   {"code": "…", "code_verifier": "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"}
   ```

   The key becomes an approved device of the user who approved it, and the answer is `200` with the user, like `GET /api/v1/me`. From then on every request it signs is theirs, until they revoke the device.

| Status | `code` | Means |
|---|---|---|
| `200` | | The device is approved. The body is the user, with `unread_notifications_count`. |
| `400` | `invalid_grant` | The code is wrong, expired or used, was handed out for another key, or the verifier doesn't match. Sign in again. |
| `401` | `invalid_signature` | The signature is bad, expired or used already. |
| `422` | | `code` or `code_verifier` is missing, or the verifier isn't 43 to 128 characters of `A-Z a-z 0-9 - . _ ~`. |
| `429` | `rate_limited` | Over 12 tries a minute for one key, or 60 from one IP, counted before signatures are checked. `Retry-After` says when to try again. |

## Available Tooling

### Development
- `composer dev` - Starts Laravel server, queue worker, log monitoring, and Vite+ dev server concurrently

### Code Quality
- `composer lint` - Runs Rector (refactoring), Pint (PHP formatting), and Oxfmt (JS/TS formatting)
- `composer test:lint` - Dry-run mode for CI/CD pipelines

### Testing
- `composer test:type-coverage` - Ensures 100% type coverage with Pest
- `composer test:types` - Runs PHPStan at the configured maximum level and TypeScript checks
- `composer test:unit` - Runs Pest tests with 100% code coverage requirement
- `composer test` - Runs the complete test suite (linting, static analysis, type coverage, unit tests)

### Maintenance
- `composer update:requirements` - Updates the PHP and frontend dependency version constraints

## License

Licensed under MIT, as declared in `composer.json`. Based on [Nuno Maduro's Laravel starter kit](https://github.com/nunomaduro/laravel-starter-kit-inertia-react).
