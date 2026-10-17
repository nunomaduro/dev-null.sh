import {
    ContactLink,
    LegalDocument,
    LegalItem,
    LegalLink,
    LegalList,
    LegalSection,
} from '@/components/legal-document';
import TextLink from '@/components/text-link';
import AppLayout from '@/layouts/app-layout';
import { legal } from '@/lib/legal';
import { edit as editSafety } from '@/routes/safety';
import { show as showTerms } from '@/routes/terms';
import { edit as editProfile } from '@/routes/user-profile';

export default function Show() {
    return (
        <LegalDocument title="Privacy policy">
            <p>
                /dev/null is a social network for developers at{' '}
                <span className="whitespace-nowrap">dev-null.sh</span>. It is
                run by {legal.operator} (“we”, “us”). This policy explains what
                we store about you, why, who else sees it, how long we keep it,
                and how to export or delete it. It covers the website, its
                public API, and the terminal commit script. Using /dev/null also
                means agreeing to our{' '}
                <TextLink href={showTerms()}>terms of service</TextLink>.
            </p>

            <LegalSection id="short-version" title="The short version">
                <LegalList>
                    <LegalItem>
                        You sign in with GitHub. We copy your public GitHub
                        profile and keep your GitHub email address private.
                    </LegalItem>
                    <LegalItem>
                        Your profile and your approved commits are public on the
                        web, including to search engines.
                    </LegalItem>
                    <LegalItem>
                        Commit text and images are sent to OpenAI to help
                        moderate them and to make commits searchable.
                    </LegalItem>
                    <LegalItem>
                        No ads, no analytics, no tracking cookies, and we don't
                        sell your data.
                    </LegalItem>
                    <LegalItem>
                        You can export your data or delete your account from
                        Settings at any time.
                    </LegalItem>
                </LegalList>
            </LegalSection>

            <LegalSection id="collect" title="What we collect">
                <p>
                    <strong className="font-semibold">From GitHub.</strong> When
                    you sign in, GitHub gives us your GitHub user ID, username,
                    name, avatar URL, bio, company, location, website, and your
                    primary verified email address. We refresh the profile each
                    time you sign in and when you press “Sync from GitHub” in
                    Settings. We also check once, at sign-in, whether you have a
                    GitHub Sponsors listing, so your profile can link to it. We
                    use your GitHub sign-in token for that check and don't keep
                    it.
                </p>
                <p>
                    <strong className="font-semibold">
                        Your follows on GitHub.
                    </strong>{' '}
                    On your first sign-in we look up who you follow on GitHub
                    and who follows you there (up to 1,000 each way). You start
                    following the people you follow on GitHub who are already on
                    /dev/null, and the /dev/null users who follow you on GitHub
                    start following you. You can unfollow anyone afterwards.
                </p>
                <p>
                    <strong className="font-semibold">
                        Your SSH key fingerprints.
                    </strong>{' '}
                    We read the public SSH keys on your GitHub account
                    (authentication and signing keys) and store only their
                    fingerprints. We use them to check that a commit sent from
                    your terminal or a GitHub Action was signed by you. We never
                    receive your private keys. The terminal script sends the
                    public keys on your machine so we can tell which one we
                    know; we compare them and don't store them.
                </p>
                <p>
                    <strong className="font-semibold">What you post.</strong>{' '}
                    Commits (/dev/null's word for posts), replies, quotes,
                    polls, images and their descriptions, scheduled commits, and
                    the link card you pick for a commit. We re-encode every
                    image you upload, which drops its metadata, such as GPS
                    location.
                </p>
                <p>
                    <strong className="font-semibold">What you do.</strong>{' '}
                    Stars, saved commits, poll votes, follows, mutes, blocks,
                    your pinned commit, your graph color, reports you file, your
                    notifications, and which log you last read.
                </p>
                <p>
                    <strong className="font-semibold">Technical data.</strong>{' '}
                    While you're signed in, we store your session with your IP
                    address, your browser's user agent, and when it was last
                    used. We also use IP addresses briefly to rate limit
                    requests, and our server logs record errors.
                </p>
                <p>
                    <strong className="font-semibold">What we work out.</strong>{' '}
                    Moderation flags for each commit (see below), a search
                    embedding of each commit's text, a score that ranks commits
                    in the Trending log from their stars, replies and age, and
                    the streaks, activity graph and achievements on your
                    profile, which come from your public activity.
                </p>
                <p>
                    We don't use analytics or advertising tools, and we don't
                    send you email.
                </p>
            </LegalSection>

            <LegalSection id="public" title="What's public">
                <p>
                    Anyone on the web, including people who aren't signed in and
                    search engines, can see:
                </p>
                <LegalList>
                    <LegalItem>
                        Your profile: name, username, avatar, bio, company,
                        location, website, when you joined, your counts, your
                        followers and who you follow, your activity graph and
                        achievements.
                    </LegalItem>
                    <LegalItem>
                        Your approved commits, replies and quotes, with their
                        images and star counts.
                    </LegalItem>
                </LegalList>
                <p>
                    The same information is available through the public read
                    API, your Atom feed, the activity graph image you can embed
                    elsewhere, and the link previews shown when someone shares a
                    /dev/null link.
                </p>
                <p>
                    Commits waiting for moderation, and rejected ones, are
                    visible only to you and the moderators. Your email address,
                    saved commits, mutes, reports, notifications and drafts are
                    private. Other people see a poll's totals, not how you
                    voted. When you star a commit, its author is told it was
                    you. When you block someone, they see your profile as
                    unavailable while they're signed in.
                </p>
            </LegalSection>

            <LegalSection id="use" title="How we use it">
                <p>
                    We use your data to run /dev/null: to show your profile and
                    commits, build your logs and notifications, suggest people
                    to follow, apply your mutes and blocks, moderate content,
                    make commits searchable, build link cards, and protect the
                    service from spam and abuse. We don't sell your data, show
                    ads, or build profiles of you for anyone else.
                </p>
            </LegalSection>

            <LegalSection id="moderation" title="Moderation and AI">
                <p>
                    Commits, replies and quotes are held until a moderator
                    approves them. Before that, we send their text and images to
                    OpenAI, which flags content that looks harmful, such as
                    threats, hate, harassment or sexual content, and images that
                    might show anyone under 18. When OpenAI's moderation is all
                    but certain, the commit is rejected automatically. Otherwise
                    a moderator decides. If your commit is rejected you get a
                    notification, sometimes with a reason.
                </p>
                <p>
                    Our moderators see commits waiting for review with OpenAI's
                    flags, and reports with their reason and details. They don't
                    see who filed a report.
                </p>
                <p>
                    Moderators can also ban someone who breaks our{' '}
                    <TextLink href={showTerms()}>terms</TextLink>. We record a
                    ban against your GitHub user ID, so it still applies if you
                    change your username or your account is deleted. While
                    you're banned you can't sign in, and your profile, commits
                    and everything else you did on /dev/null are hidden from
                    everyone. A ban doesn't delete any of it. If the ban is
                    lifted, it all comes back.
                </p>
            </LegalSection>

            <LegalSection id="search" title="Search">
                <p>
                    To make commits searchable, we send each commit's text to
                    OpenAI, which turns it into an embedding: a list of numbers
                    that describes what the text is about. We store it with the
                    commit. When anyone searches commits, signed in or not, we
                    send their search to OpenAI, along with the text of the
                    commits that might match, so OpenAI can judge which are
                    relevant.
                </p>
            </LegalSection>

            <LegalSection id="third-parties" title="Who else sees your data">
                <LegalList>
                    <LegalItem>
                        <strong className="font-semibold">GitHub</strong>, for
                        signing in, your profile, your follows and your SSH
                        keys. See{' '}
                        <LegalLink
                            href="https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            GitHub's privacy statement
                        </LegalLink>
                        .
                    </LegalItem>
                    <LegalItem>
                        <strong className="font-semibold">OpenAI</strong>, for
                        moderation and search, as described above. See{' '}
                        <LegalLink
                            href="https://openai.com/policies/privacy-policy/"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            OpenAI's privacy policy
                        </LegalLink>
                        .
                    </LegalItem>
                    <LegalItem>
                        <strong className="font-semibold">
                            {legal.hostingProvider}
                        </strong>
                        , which hosts our servers and database in{' '}
                        {legal.hostingRegion}, and{' '}
                        <strong className="font-semibold">
                            {legal.imageStorageProvider}
                        </strong>
                        , which stores uploaded images.
                    </LegalItem>
                    <LegalItem>
                        <strong className="font-semibold">
                            Websites you link to.
                        </strong>{' '}
                        When a commit has a link, our server fetches that page
                        once to build its link card, identifying itself as
                        /dev/null. The card's image is then loaded by each
                        reader's browser straight from that website, without
                        telling it which page it's on.
                    </LegalItem>
                    <LegalItem>
                        <strong className="font-semibold">
                            Services your browser loads.
                        </strong>{' '}
                        Avatars load from GitHub. Video and music players in
                        commits (YouTube, Vimeo, Loom, Twitch, Suno, Spotify,
                        SoundCloud, Apple Music, CodePen and asciinema) load
                        only when you press play. These services see your IP
                        address and follow their own privacy policies.
                    </LegalItem>
                    <LegalItem>
                        <strong className="font-semibold">
                            Authorities and courts
                        </strong>
                        , when the law requires it, or when we need to protect
                        someone's safety or /dev/null itself.
                    </LegalItem>
                </LegalList>
                <p>
                    GitHub and OpenAI are based in the United States, so your
                    data may be processed there.
                </p>
            </LegalSection>

            <LegalSection id="cookies" title="Cookies and browser storage">
                <p>
                    We only use cookies /dev/null needs to work. None of them
                    track you.
                </p>
                <LegalList>
                    <LegalItem>
                        <code className="font-mono text-[13px]">
                            devnull-session
                        </code>{' '}
                        and{' '}
                        <code className="font-mono text-[13px]">
                            XSRF-TOKEN
                        </code>
                        : your session, and protection against forged requests.
                        They expire after two hours without activity.
                    </LegalItem>
                    <LegalItem>
                        <code className="font-mono text-[13px]">
                            remember_web_…
                        </code>
                        : keeps you signed in, for up to 400 days or until you
                        log out.
                    </LegalItem>
                    <LegalItem>
                        <code className="font-mono text-[13px]">
                            appearance
                        </code>
                        ,{' '}
                        <code className="font-mono text-[13px]">
                            who_to_follow
                        </code>{' '}
                        and{' '}
                        <code className="font-mono text-[13px]">
                            who_to_follow_round
                        </code>
                        : your theme and who-to-follow choices, for a year (the
                        last one for a day).
                    </LegalItem>
                </LegalList>
                <p>
                    Your browser also keeps your unsent drafts and their images,
                    your theme, and where you last stopped reading each log.
                    These stay on your device. Drafts are cleared when you log
                    out.
                </p>
            </LegalSection>

            <LegalSection id="retention" title="How long we keep it">
                <LegalList>
                    <LegalItem>
                        Your profile and activity: until you delete your
                        account.
                    </LegalItem>
                    <LegalItem>
                        A commit you delete disappears from /dev/null straight
                        away, and you have 30 seconds to undo it. Its images are
                        erased after those 30 seconds. Its text stays in our
                        database, hidden, until you delete your account.
                    </LegalItem>
                    <LegalItem>
                        Rejected commits: until you delete them or your account.
                    </LegalItem>
                    <LegalItem>
                        If you're banned: your GitHub user ID, for as long as
                        the ban lasts, even if your account is deleted. Your
                        account and everything in it stay in our database,
                        hidden, until the ban is lifted or the account is
                        deleted.
                    </LegalItem>
                    <LegalItem>
                        Sessions: two hours after your last activity, or up to
                        400 days if you stay signed in.
                    </LegalItem>
                    <LegalItem>
                        Server logs: {legal.logRetention}. Database backups:{' '}
                        {legal.backupRetention}.
                    </LegalItem>
                </LegalList>
            </LegalSection>

            <LegalSection id="your-data" title="Exporting and deleting">
                <p>
                    <strong className="font-semibold">Export.</strong> In{' '}
                    <TextLink href={editProfile()}>Settings → Profile</TextLink>
                    , “Export your data” downloads a JSON file with your profile
                    (including your email), all your commits and replies
                    (including pending, rejected and deleted ones), your polls
                    and votes, stars, saved commits, follows, followers, mutes,
                    blocks, notifications, SSH key fingerprints, and sessions.
                    Images are included as links.
                </p>
                <p>
                    <strong className="font-semibold">Delete.</strong> In{' '}
                    <TextLink href={editProfile()}>Settings → Profile</TextLink>
                    , “Delete account” deletes your account straight away. There
                    is no grace period. It erases your profile, your commits and
                    replies (including deleted ones) and their images, every
                    reply under your commits, your stars, saved commits, votes,
                    follows, mutes, blocks, notifications, reports and SSH key
                    fingerprints. One thing stays: a reply of yours that someone
                    else replied to is kept, without your name, as a “[deleted]”
                    placeholder so their reply still makes sense. Its text stays
                    in our database, hidden. Deleting your account doesn't touch
                    your GitHub account. If you sign in with GitHub again, you
                    start a new /dev/null account.
                </p>
                <p>
                    <strong className="font-semibold">If you're banned.</strong>{' '}
                    You can't sign in to export or delete your data yourself.
                    Email <ContactLink /> and we'll send you a copy or delete
                    your account for you. Deleting it erases everything listed
                    above, but we keep your GitHub user ID for as long as the
                    ban lasts, so you can't sign in again with the same GitHub
                    account.
                </p>
                <p>
                    <strong className="font-semibold">Everything else.</strong>{' '}
                    Change your profile on GitHub, then sync it. Delete any
                    commit at any time. Manage mutes and blocks in{' '}
                    <TextLink href={editSafety()}>Settings → Safety</TextLink>.
                    To stop /dev/null reading your GitHub account, revoke its
                    access in{' '}
                    <LegalLink
                        href="https://github.com/settings/applications"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        GitHub's application settings
                    </LegalLink>
                    .
                </p>
            </LegalSection>

            <LegalSection id="rights" title="Your rights">
                <p>
                    Depending on where you live, you may have the right to
                    access, correct, delete or move your data, to object to how
                    we use it, and to complain to your data protection
                    authority. Most of this you can do yourself in Settings. For
                    anything else, email <ContactLink />.
                </p>
                <p>
                    Where the law asks for a legal basis, we rely on our
                    contract with you to run /dev/null, and on our legitimate
                    interest in keeping it safe and free of spam and abuse,
                    which covers moderation, search and rate limiting.
                </p>
            </LegalSection>

            <LegalSection id="children" title="Children">
                <p>
                    /dev/null isn't for children under 13, which is also
                    GitHub's minimum age. If we learn that someone under 13 has
                    an account, we delete it.
                </p>
            </LegalSection>

            <LegalSection id="changes" title="Changes to this policy">
                <p>
                    When we change this policy, we update the date at the top.
                    If a change matters, we'll tell you on /dev/null before it
                    takes effect.
                </p>
            </LegalSection>

            <LegalSection id="contact" title="Contact">
                <p>
                    {legal.operator}, {legal.operatorAddress}. Email{' '}
                    <ContactLink />.
                </p>
            </LegalSection>
        </LegalDocument>
    );
}

Show.layout = AppLayout;
