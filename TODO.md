# /dev/null — TODO

A social network for developers. Sign in with GitHub, have a Twitter-style profile imported from GitHub, follow people, make commits (short text updates), and read the log.

**Keep it stupidly simple:** no comments, images, or DMs.

---

## Decisions

- **Auth:** GitHub is the only way to sign in (`laravel/socialite`). No email/password, password reset, email verification, 2FA, or passkeys — Fortify removed.
- **Profile:** Twitter-style, mirrored from GitHub on every login (avatar, name, username, bio, location, website, company). No editing on /dev/null.
- **Commits:** a post is a *commit*: a required title (max 72 characters, like a git subject line) and an optional description (max 500 characters). The author can create and delete — no editing (no `--amend`). Rate limited to 10 commits per minute per user.
- **Log:** a timeline is a *log*. "Following" = my commits + commits from people I follow; "Everyone" = all commits. Newest first, Inertia infinite scroll. A profile shows that user's log. Each log is a `Log` enum case served by `LogController@show`.
- **Metaphor, nouns only:** the git vocabulary is limited to *commit* and *log*. No branches, forks, pushes, diffs, or PRs. A *star* is the engagement action (star a commit), not a git ref.
- **Visibility:** everything except the landing page requires login.

---

## Data model

**users** — `id` (uuid), `github_id` (unique), `username` (unique), `name`, `email` (nullable, hidden), `avatar_url`, `bio`, `location`, `website`, `company`, `preferred_lane` (nullable, 0–5, hidden), `remember_token`, timestamps

**commits** — `id` (uuid), `user_id` (cascade delete), `title` (72), `description` (nullable, 500), timestamps; indexes on (`user_id`, `created_at`) and `created_at`

**follows** — (`follower_id`, `followed_id`) primary key, both cascade delete, timestamps; index on `followed_id`

**notifications** — `id` (uuid), `recipient_id` / `actor_id` (users, cascade delete), `type` (`NotificationType`), `commit_id` (nullable, cascade delete), `key` (unique per recipient: `followed:{actor}`, `replied:{commit}`, `quoted:{commit}`), `read_at` (nullable), timestamps

---

## Milestone 1 — Sign in with GitHub ✅

- [x] Install `laravel/socialite`, add `services.github` config + `.env.example` keys
- [x] Update `users` migration, `User` model, and `UserFactory`
- [x] Action `SyncGitHubUser` — find by `github_id`, otherwise create; sync profile fields on each login
- [x] `SessionController@create` → redirect to GitHub; `@store` → handle callback, log in, redirect to intended URL / timeline; `@destroy` → logout
- [x] Failure cases (denied access, invalid state) → landing page with an error toast
- [x] Remove all other starter-kit auth (routes, actions, controllers, requests, pages, components, tests, Fortify, `@laravel/passkeys`, `input-otp`)
- [x] Delete account without password confirmation

## Milestone 2 — Landing page & profile ✅

- [x] Landing page: name, pitch, "Sign in with GitHub". Logged-in users redirect to timeline.
- [x] Profile page `/@{username}`: avatar, name, `@username`, bio, company, location, website, GitHub link, joined date, post/following/follower counts, posts with infinite scroll
- [x] Settings → Profile: read-only (synced from GitHub), "Edit on GitHub", delete account

## Milestone 3 — Posts ✅

- [x] `posts` migration, `Post` model, `PostFactory`, `PostPolicy`
- [x] Actions `CreatePost`, `DeletePost`
- [x] `PostController@store`, `@destroy` + `CreatePostRequest`
- [x] Composer box with character counter at the top of the timeline
- [x] `posts` rate limiter (10 per minute per user)

## Milestone 4 — Follow & timeline ✅

- [x] `follows` migration + `following()` / `followers()` / `isFollowing()` on `User`
- [x] Actions `FollowUser` (idempotent, rejects self-follow), `UnfollowUser`; `UserPolicy@follow`
- [x] `UserFollowController@store`, `@destroy`; Follow/Unfollow button on profiles
- [x] `TimelineController@show` at `/timelines/{timeline}` (`App\Enums\Timeline`: `following`, `everyone`), each backed by a `App\Queries\*TimelineQuery`, cursor-paginated via `Inertia::scroll()` + `<InfiniteScroll>`
- [x] Empty states

## Milestone 5 — Commits & logs ✅

- [x] `posts` migration → `commits` (edited in place, no real data yet): `body` → `title` (72) + `description` (nullable, 500)
- [x] `Post` → `Commit` model + `CommitFactory`; `User::posts()` → `commits()`
- [x] Actions `CreateCommit` (title + optional description), `DeleteCommit`
- [x] `CommitController@store`, `@destroy` + `CreateCommitRequest` / `DeleteCommitRequest`; routes `/commits`, `/commits/{commit}`; `commits` rate limiter
- [x] `Timeline` → `Log` everywhere: `App\Enums\Log`, `App\Queries\*LogQuery`, `LogController@show` at `/log/{log}`
- [x] Composer: title input with a 72-char counter, optional description textarea, "Commit" button
- [x] `CommitCard`: title in medium weight, description muted below; short hash in monospace next to the timestamp, from the **end** of the UUID (v7 UUIDs start with a timestamp). Computed on the frontend — Rector wants model accessors `protected`, the strict arch preset forbids protected methods.
- [x] Copy: "Log" nav item, "Commits" profile count, empty states, delete confirmation

## Milestone 6 — Notifications ✅

- [x] `notifications` migration, `Notification` model + `NotificationFactory`, `NotificationType` enum (`Followed`, `Replied`), `User::notifications()`
- [x] A notification is a pointer, not a copy: hard deletes (reply, parent, actor, recipient) cascade through foreign keys; reversible states are filtered by `NotificationBuilder::relevant()` (follow still exists, reply still approved), so unfollow/reject hide it and refollow/re-approve bring it back as it was
- [x] Actions `NotifyAboutFollow`, `NotifyAboutReplies` (`insertOrIgnore` on the unique `key`, so never twice); called from `FollowUser`, `CreateCommit` (admins), `ApproveCommit`, `ApproveCommits` — not from observers, since bulk approvals and pivot detaches fire no model events
- [x] `NotificationController@index` at `/notifications`; each page is marked as read with `defer()` after it is sent (`MarkNotificationsAsRead`), so it still renders as unread
- [x] Nav item with unread badge (`auth.unreadNotificationsCount`), not prefetched so hovering never marks anything as read; a reply links to its commit page, a follow to the profile

---

## Next up

- [ ] Create a GitHub OAuth app and fill `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` (callback: `{APP_URL}/auth/github/callback`)
- [ ] Re-run `php artisan boost:install` so AI guidelines/skills stop referencing Fortify
- [ ] Rewrite `README.md` for /dev/null
- [ ] Handle GitHub username renames colliding with an existing (stale) username
- [x] Deleting a post far down the feed reloads the first page — consider optimistic removal

## Not now

Comments, reposts without a comment (quotes are in), editing (amend), images/media, mentions, hashtags, search, DMs, other OAuth providers, public API, admin/moderation tools, blocking/muting, header/banner images, importing real GitHub commits, email/push notifications. Stars are in: anyone who can see a commit can star and unstar it, and the count sits on the commit.
