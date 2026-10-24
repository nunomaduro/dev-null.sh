# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Developers who left X or Bluesky, or are thinking about it, and want a feed with only developers in it: a place to post what they are building, talk tech with peers, and follow the people whose work they care about. Every account is a GitHub account. Guests and crawlers can read public content without signing in.

## Product Purpose

/dev/null is a social network for developers: sign in with GitHub, commit short posts about code and projects, follow people, and read the logs. Success is developers posting here regularly instead of elsewhere, and threads that are real technical conversations between peers.

## Positioning

- **Verified developers.** Every account is a real GitHub user, and the profile is mirrored from GitHub and cannot be edited here. A general-purpose network cannot truthfully claim that everyone on it is a developer with a public GitHub identity.
- **Native to how developers work.** Posting from the terminal or a GitHub Action with an SSH signature, code blocks with highlighting, keyboard shortcuts, git vocabulary, GitHub links rendered as `owner/repo#123`.
- **About developers and their projects.** The content is code, releases, side projects, and the people building them, not general chatter.

## Operating Context

- Surfaces: the web app (also installable as a PWA), the mobile app through a signed JSON API, the terminal script and GitHub Action, a public read-only API, Atom feeds, and an activity graph SVG for GitHub READMEs.
- Guests can read the Latest and Trending logs, profiles, approved commits and their threads, and search. Everything that writes or is personal (Following log, notifications, Saved, Remotes, Settings) needs sign-in.
- Site admins (`ADMINS`) moderate (`/moderation`) and run the site (`/operations`).
- Exact behavior, limits, and edge cases for every feature live in [KNOWLEDGE_BASE.md](KNOWLEDGE_BASE.md) and in the code; this file only records what must stay true.

## Capabilities and Constraints

What exists, one line each (details in the knowledge base):

- **Commits:** text with code, images, link cards and embedded players, or a poll; replies, quotes, threads, scheduling, translation.
- **Topics:** AI-generated labels for substantive approved root commits; no topic browsing yet.
- **Logs:** Following, Latest, and Trending.
- **Engagement:** follows, stars, saved commits, pins, mentions, and pastéis (an opt-in daily allowance of pastries).
- **Profiles:** mirrored from GitHub, with an activity graph, streaks, achievements, a Unix rank, and a live marker while you stream on YouTube.
- **Remotes:** private one-to-one and group messages (orgs).
- **Notifications:** in-app activity, plus independently controlled browser and sound alerts for Remotes while the site is open, enabled by default; only browser banners require notification permission.
- **Search:** developers by name, commits by meaning.
- **Emails:** an occasional update the admins write to everyone, or to a filtered audience, with one-click unsubscribe. Never per-activity notifications.
- **Safety:** mute, block, reports, and moderation.
- **Bots:** Tom answers questions about /dev/null from the knowledge base, the public repository, and aggregate community statistics; World answers "hello world". They use @tom and @world until those GitHub owners sign in, then keep working as @devnull-tom and @devnull-world.
- **Data:** JSON export and account deletion.

Rules every feature must keep:

- **An account is a GitHub account.** Accounts are only ever created from GitHub, and identity (name, avatar, bio, username) always comes from GitHub. Another sign-in method may only be a second way into an existing GitHub-created account, such as Sign in with Apple in the mobile app, which the App Store requires.
- **Nothing is public before moderation.** Content shows to others and notifies anyone only once approved; pending and rejected content is visible only to its author and to moderators. Mutes and blocks filter everywhere: logs, threads, search, suggestions, notifications.
- **Commits are immutable.** Create and delete, never edit. Deletion can be undone briefly, and a deleted commit leaves an authorless tombstone so other people's replies survive.
- **Store as little as possible.** Assume the database has leaked: no data without a feature that needs it, sensitive content encrypted, no secrets stored, and everything about a person erased with their account and included in their export (see `.ai/guidelines/data.blade.php`). The one deliberate exception is moderation: bans and timeouts are kept by GitHub account after deletion, holding only the GitHub id, reason, and dates, so deleting an account and signing up again can't escape or reset them.
- **Same rules on every surface.** Web, mobile API, terminal, and Action share validation, moderation, visibility, and rate limits.

Deliberately excluded: sign-up or identity outside GitHub, editing commits, and email or push notifications.

## Brand Commitments

- Name: **/dev/null**, written exactly like the path (lowercase, both slashes). The domain is `dev-null.sh`. `devnull` (no slashes) is only for identifiers where a slash cannot go: the database, file names, env vars, the SSH signature namespaces.
- Voice: dry, terminal-literate humor. Understated wit; copy may wink at Unix and `/dev/null`, but stays clear and never obscures what an action does.
- Lore is part of the product: Tom (everyone's first friend, after MySpace Tom), pastéis (a tribute to the pastéis de nata brought to the livestream), @world, the Unix-named achievements. Extend it with restraint, never at the expense of clarity. The stories behind it are in the knowledge base.
- Current tagline in use: "A social network for developers."
- Vocabulary: a post is a **commit** and a timeline is a **log**, in the UI and in code (`Commit` model, `LogController`, `/log/{log}`). The git metaphor stops at those two nouns, except inside Remotes, which carries its own small vocabulary: a conversation is a **remote**, sending is a **push**, a read receipt is **pulled**, a request is **fetched** until **merged** or **dropped**, archiving is **stash** and **pop**, a group is an **org** of **maintainers** and **contributors**, and **HEAD** marks where you left off. No branches, forks, diffs, or PRs. **Star** is the engagement verb, not a git ref.

## Evidence on Hand

- Real users and activity, including popular commits by well-known developers such as @taylorotwell and @rauchg.
- Usage stats are public but change daily: never hardcode numbers in copy or docs.
- Do not fabricate user counts, quotes, activity, or endorsements. Seeded and factory data is for development only.

## Product Principles

1. **GitHub is the source of truth for identity.** Never add profile editing on /dev/null, or a way to create an account without GitHub.
2. **Built for developers and their work.** A feature earns its place by helping developers post about what they build or talk with peers, not by driving engagement.
3. **Keep it simple.** Prefer fewer, sharper features; no engagement mechanics for their own sake.
4. **Words over mechanics.** The commit body is the content; chrome and metadata stay subordinate to it.
5. **Wit in the copy, clarity in the action.** Humor lives in tone, never in labels a user needs to understand.
