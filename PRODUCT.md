# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Any developer with a GitHub account. They come to post short text updates, follow other developers, and read a timeline of what those people are saying. Every user is identified by their GitHub account; there is no other way in.

## Product Purpose

/dev/null is a social network for developers: sign in with GitHub, get a profile mirrored from GitHub, follow people, post short text, and read a timeline. Success is developers choosing to post and read here because the people on it are verifiably developers.

## Positioning

Identity is GitHub. Every account is a real GitHub user, and the profile (avatar, name, username, bio, company, location, website) is mirrored from GitHub on every login, or on demand with "Sync from GitHub" in settings, and cannot be edited on /dev/null. A general-purpose network cannot truthfully claim that every account is a developer with a public GitHub identity.

## Operating Context

- Sign-in is GitHub OAuth only. Profile changes happen on GitHub ("Edit on GitHub"), then sync at next login or with "Sync from GitHub" (once a minute).
- Everything except the landing page, the public read API, and profile feeds requires login.
- Public read API: read-only JSON under `/api/v1`, no authentication, for public profiles (with counts), a user's commits or replies, and single commits. It returns exactly what a guest's link preview may show: approved, non-deleted commits only (with their images), approved reply counts, and a parent only while it is approved. Never emails or other hidden profile fields. 60 requests a minute per IP.
- Profile feeds: every profile has an Atom feed of its latest 50 approved top-level commits at `/@{username}/feed.atom`, their images as enclosures, advertised with `<link rel="alternate">` on the profile's guest preview.
- First sign-in leads to a short setup at `/onboarding`: confirm the mirrored profile, pick a color, follow a few suggested developers, learn the composer, `?` shortcuts and terminal commits, then land on the log (or the page the user was headed to). Skippable at any step (Skip setup or Esc); finishing or skipping ends it for good. Accounts that existed before it never see it.
- Profile links (`/@{username}`) and links to approved commits (`/@{username}/commits/{id}`) unfurl with a link preview (the author and their bio or counts, or the commit body and reply count) for guests and crawlers; a guest opening one sees the landing page, and the profile or commit itself stays behind login. Pending and rejected commits are a 404 for guests.
- Each profile's commit activity graph is also public as an SVG at `/@{username}/graph.svg` (`?theme=light|dark|auto`, light by default), for embedding in GitHub profile READMEs. It draws the last year of approved commits with the streaks, in the user's lane, and is cached for an hour.
- Two logs: **Following** (my commits plus people I follow) and **Everyone** (all commits), newest first, infinite scroll.
- Profiles live at `/@{username}` with commit, following, and follower counts, a commit activity graph with longest and current streaks, and Commits and Replies tabs. Commits is the default and lists the user's top-level commits; `?tab=replies` lists their authored replies with visible parent context. Both feeds use infinite scroll.
- Operations (`/operations`, admins only; a 404 for everyone else): database and cache connectivity, the GitHub API rate limit when GitHub credentials are configured, the database queue backlog per queue with the age of its oldest job, the most recent failed jobs (each can be retried or forgotten), pending moderation and link previews, link previews that came back without metadata in the last 24 hours, and signups, commits, and replies over the last 24 hours and 7 days. It refreshes every 15 seconds.
- Settings: read-only profile, color (pick one of the six graph lanes or keep the automatic one; the only profile setting /dev/null owns), appearance (light/dark/system), delete account.
- Settings: read-only profile, color (pick one of the six graph lanes or keep the automatic one; the only profile setting /dev/null owns), the Markdown snippet and live preview to embed the activity graph SVG, appearance (light/dark/system), delete account.

## Capabilities and Constraints

- Commits (posts): plain text, 3–4,000 characters; create and delete only (no editing). Rate limited to 10 commits per minute per user.
- Follow/unfollow, idempotent; self-follow rejected.
- World: the account @world replies "Hello, {name}." when an approved commit's whole text is hello world, including "Hello, World!". A pending one is answered once it is approved, and only once. @world does not sign in.
- GitHub follows come over once, on first sign-in: you follow the developers you follow on GitHub, and the ones following you there follow you (up to 1,000 each way), with the usual follow notifications. It only ever adds follows, so unfollowing someone sticks. `php artisan follows:import` does the same, silently, for everyone not imported yet.
- Replies: any visible commit can be replied to; a reply is itself a commit (same 4,000 limit, moderation, and rate limit). Threads expand inline under a commit; logs and the profile's Commits tab list top-level commits only, while the profile's Replies tab lists that user's replies. Every commit has its own page with its ancestors above it and its replies expanded; it is behind login like everything else. Deleting a commit removes it from logs, profiles, counts, and its own page, but never takes other people's replies with it: while it still has visible replies it stays in its thread as a `[deleted]` tombstone with no author or body, and it can no longer be replied to. Its author can undo a deletion for 30 seconds, which brings it back exactly as it was, without notifying anyone again. Deleting an account erases that user's commits and replies for good, along with every reply under their commits; replies other people left under their replies survive, under the same authorless `[deleted]` tombstone.
- Quotes: any visible commit can be quoted. A quote is a new top-level commit (same 4,000 limit, moderation, and rate limit) with the quoted commit embedded under its body as a compact card that opens the quoted commit's page; a reply cannot also quote. The embed only ever shows what the viewer can see: a deleted quoted commit leaves a `[deleted]` tombstone, one the viewer can no longer see (pending or rejected) reads `[unavailable]` with no author or body, and erasing an account removes embeds of erased commits, while approved quoted replies retained as authorless thread tombstones still read `[deleted]`. Quotes have no count.
- Stars: anyone who can see a commit can star and unstar it. Starring is idempotent. The count sits on the commit. A star is the engagement action, named the way GitHub names it, and it is not a git ref.
- Mentions: `@username` in a commit or reply mentions that developer and links to their profile. Typing `@` in either composer suggests developers (people you follow on a bare `@`, then matches by name or username, people you follow first); ↑↓ to move, ↵ or Tab to pick, Esc to dismiss. Any number of people can be mentioned in one commit. Emails, scoped packages (`@inertiajs/react`) and domains (`@vue.js`) are not mentions.
- Reports: anyone who can see an approved commit or reply that isn't theirs can report it privately, with a reason and optional details (up to 500 characters). One report per commit per person; reporting is rate limited and never changes the commit's visibility. Moderators review open reports oldest first without the reporter's identity, and either dismiss a report to keep the commit or reject the commit, which clears its reports from the queue. Deleting a commit removes its reports.
- Preview: both composers toggle between writing and a preview of the draft rendered exactly as the posted commit (code highlighting, inline code, emoji, mentions, links, attached images), with the URL that will get the link card marked. The eye button or ⌘/Ctrl+Shift+P toggles it; Esc in the preview goes back to writing.
- Link cards: a commit or reply with links shows a card (title, description, image) for one of them. The first link gets it by default; in either composer the author can pick another link from the body or no card. Commits from the terminal always use the first link.
- GIFs: with a GIPHY key configured, a commit, reply, or quote can carry one GIF from GIPHY's catalog — never alongside uploaded images or a poll, and never on a scheduled commit. The GIF is hotlinked from GIPHY with creator attribution; /dev/null stores only the GIF's URLs plus its id, title, creator, and dimensions, as GIPHY's API terms require.
- Links: URLs and bare domains in a commit or reply link out. Like X, a long one shows shortened: no `https://` or `www.`, the whole host, then at most 20 characters ending in `…`. Hovering shows the full URL, and copying the text gives it. Links in code stay as written. Link previews (title text and image) shorten them the same way.
- Notifications: in-app only, for a new follower, for an approved reply to one of your commits (only the direct parent's author; never for replying to yourself), for an approved quote of one of your commits (never for quoting yourself), and for an approved commit or reply that mentions you (never for mentioning yourself; the parent's author of a reply or the quoted author of a quote hears about it as a reply or quote, not twice). A notification points to the follow, reply, quote, or mention, it never copies it: it disappears when the commit, the commit it replies to or quotes, or either user is deleted, and is hidden while the follow is undone or the commit is rejected. A follower notifies once, ever: unfollowing and following again never pings twice. Pending replies, quotes, and mentions notify nobody until approved. No notification for moderation outcomes. A new follower can be followed back, or unfollowed, right from the list. Unread count in the nav; a page is marked as read once shown.
- People search: find developers by name or GitHub username (⌘K). Commits are not searchable.
- Rate limits, per user: stars and saves 60/min each, pins 20/min, follows 30/min, people lookups (search, mention suggestions, hover cards) 120/min combined, exports 3/hour. Throttled actions keep the page and show a toast; throttled pages show the 429 page.
- Public read API and Atom feeds: read-only, so nothing can be created, starred, or followed through them, and there are no API tokens. Lists are cursor-paginated, 20 per page.
- Deliberately excluded for now: likes/reactions, reposts without a comment of your own (quotes are in), hashtags, searching commits, DMs, other OAuth providers, a write API or API tokens, moderation tools, blocking/muting, header/banner images. Future work must not design for these as if they exist.
- GitHub username renames: signing in takes the current GitHub username. If a stale account still holds it because its owner renamed on GitHub, that account moves to a `_renamed-<github id>` placeholder until its owner signs in again. Accounts are never merged.

## Brand Commitments

- Name: **/dev/null**, written exactly like the path (lowercase, both slashes). The domain is `dev-null.sh`. `devnull` (no slashes) is only for identifiers where a slash cannot go: the database, file names, env vars, the SSH signature namespace.
- Voice: dry, terminal-literate humor. Understated wit; copy may wink at Unix and `/dev/null`, but stays clear and never obscures what an action does.
- Current tagline in use: "A social network for developers."
- Vocabulary: a post is a **commit** and a timeline is a **log**, in the UI and in code (`Commit` model, `LogController`, `/log/{log}`). The git metaphor stops at those two nouns: no branches, forks, pushes, diffs, or PRs. **Star** is the engagement verb, not a git ref.

## Evidence on Hand

- No users, testimonials, metrics, or press yet. Do not fabricate user counts, quotes, activity, or endorsements.
- Seeded/factory data exists for development only and is not real evidence.

## Product Principles

1. **GitHub is the source of truth for identity.** Never add profile editing on /dev/null or alternate sign-in.
2. **Keep it stupidly simple.** Text posts, follows, stars, timelines. Every addition must justify itself against the "not now" list.
3. **Words over mechanics.** The post body is the content; chrome and metadata stay subordinate to it.
4. **Wit in the copy, clarity in the action.** Humor lives in tone, never in labels a user needs to understand.
