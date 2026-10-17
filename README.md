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

### GIFs

GIF search is bring-your-own-key: connect your own free [GIPHY API key](https://developers.giphy.com) under Settings → Profile → Giphy and every composer gains a GIF picker: search GIPHY and attach one GIF to a commit, reply, or quote — never alongside uploaded images or a poll. Searches run on the connected key and its quota, so no paid key is shared by the instance; without one the picker is hidden. The browser talks to GIPHY directly and commits store only the GIF's URLs and its metadata — nothing is downloaded or rehosted.

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
