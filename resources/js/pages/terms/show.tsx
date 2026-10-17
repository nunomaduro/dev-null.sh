import {
    ContactLink,
    LegalDocument,
    LegalItem,
    LegalList,
    LegalSection,
} from '@/components/legal-document';
import TextLink from '@/components/text-link';
import AppLayout from '@/layouts/app-layout';
import { legal } from '@/lib/legal';
import { show as showPrivacy } from '@/routes/privacy';
import { edit as editSafety } from '@/routes/safety';
import { edit as editProfile } from '@/routes/user-profile';

export default function Show() {
    return (
        <LegalDocument title="Terms of service">
            <p>
                These terms are an agreement between you and {legal.operator}{' '}
                (“we”, “us”) about using /dev/null, the social network for
                developers at{' '}
                <span className="whitespace-nowrap">dev-null.sh</span>,
                including its public API and the terminal commit script. By
                signing in or using /dev/null, you agree to them. Our{' '}
                <TextLink href={showPrivacy()}>privacy policy</TextLink>{' '}
                explains what we do with your data.
            </p>

            <LegalSection id="zero-tolerance" title="Zero tolerance">
                <p>
                    /dev/null has no tolerance for objectionable content or
                    abusive users. We remove content that breaks these terms and
                    remove the accounts of people who post it or who abuse
                    others. Commits wait for a moderator's approval before
                    anyone else can see them, and anyone can report a commit or
                    block a user at any time.
                </p>
            </LegalSection>

            <LegalSection id="eligibility" title="Who can use /dev/null">
                <p>
                    You need a GitHub account, and you must be at least 13 years
                    old and old enough where you live to agree to these terms.
                    You can't use /dev/null if we've removed you from it before.
                </p>
            </LegalSection>

            <LegalSection id="account" title="Your account">
                <p>
                    Your /dev/null account is tied to your GitHub account, and
                    your profile comes from it. You're responsible for
                    everything done with your account, including commits signed
                    with your SSH keys from a terminal or a GitHub Action. Keep
                    your GitHub account and your private keys safe. If a key
                    leaks, remove it from GitHub and tell us.
                </p>
            </LegalSection>

            <LegalSection id="content" title="Your content">
                <p>
                    You own what you post. To run /dev/null, you give us a
                    worldwide, non-exclusive, royalty-free license to store,
                    copy, display and distribute it: on /dev/null, through the
                    public API and feeds, in link previews and search engines,
                    and to the services that help us moderate it and make it
                    searchable. The license ends when you delete the content,
                    except for what our privacy policy says we keep.
                </p>
                <p>
                    Only post what you have the right to post. Your approved
                    commits are public, so assume anyone can read them.
                </p>
            </LegalSection>

            <LegalSection id="rules" title="What you can't post">
                <LegalList>
                    <LegalItem>
                        Harassment, bullying, intimidation, or content that
                        targets a person or group.
                    </LegalItem>
                    <LegalItem>
                        Hate speech: attacks on people for their race,
                        ethnicity, national origin, religion, caste, sex,
                        gender, gender identity, sexual orientation, disability,
                        age or serious illness.
                    </LegalItem>
                    <LegalItem>
                        Threats, violence, or its incitement or glorification,
                        including “jokes” about having a weapon or planning an
                        attack.
                    </LegalItem>
                    <LegalItem>
                        Sexual content or nudity of any kind. Anything that
                        sexualizes or endangers children is reported to the
                        authorities.
                    </LegalItem>
                    <LegalItem>
                        Encouragement of suicide or self-harm.
                    </LegalItem>
                    <LegalItem>
                        Someone else's private information, such as their
                        address, phone number, documents or credentials, without
                        their permission.
                    </LegalItem>
                    <LegalItem>
                        Spam, scams, phishing, malware, or misleading links.
                    </LegalItem>
                    <LegalItem>
                        Impersonation of another person or organization.
                    </LegalItem>
                    <LegalItem>
                        Content that infringes someone's copyright, trademark or
                        other rights, or that is otherwise illegal.
                    </LegalItem>
                </LegalList>
            </LegalSection>

            <LegalSection id="conduct" title="What you can't do">
                <LegalList>
                    <LegalItem>
                        Abuse other users, or get around a block, a mute, a
                        moderation decision or a removal, including with another
                        account.
                    </LegalItem>
                    <LegalItem>
                        Fake engagement, such as stars or follows from accounts
                        you control.
                    </LegalItem>
                    <LegalItem>
                        Get around rate limits, scrape /dev/null beyond what the
                        public API allows, or use /dev/null's data to send spam.
                    </LegalItem>
                    <LegalItem>
                        Attack, overload or probe /dev/null, or try to access
                        accounts or data that aren't yours.
                    </LegalItem>
                </LegalList>
            </LegalSection>

            <LegalSection id="reporting" title="Reporting content and users">
                <p>
                    To report a commit or reply, press Report under it, pick a
                    reason, and add details if you like. Reports are private:
                    the moderators don't see who sent them, and the author isn't
                    told.
                </p>
                <p>
                    To report a user, report the commits that show the problem,
                    or email <ContactLink /> with their username and what
                    happened. Email us too about anything you can't report from
                    a commit, or anything urgent.
                </p>
                <p>
                    Moderators review reports and aim to act on them within 24
                    hours. When content breaks these terms, they remove it, and
                    they remove the accounts of people who post it.
                </p>
            </LegalSection>

            <LegalSection id="blocking" title="Blocking and muting">
                <p>
                    You can mute or block anyone from the menu on their profile,
                    and manage both in{' '}
                    <TextLink href={editSafety()}>Settings → Safety</TextLink>.
                    Muting keeps someone's commits out of your logs, threads and
                    search, and stops their notifications. Blocking also stops
                    you following, replying to or starring each other, or seeing
                    each other's commits. Neither person is told.
                </p>
            </LegalSection>

            <LegalSection id="moderation" title="Moderation">
                <p>
                    Moderators approve or reject commits, replies and quotes
                    before others can see them, with help from automated checks
                    that can reject clearly harmful content on their own.
                    Moderators can also reject a commit after it was approved.
                    We tell you when we reject your commit, sometimes with a
                    reason. We can remove content, and suspend or permanently
                    remove accounts, when they break these terms or the law,
                    without notice when the case is serious.
                </p>
            </LegalSection>

            <LegalSection id="ending" title="Leaving /dev/null">
                <p>
                    You can delete your account at any time in{' '}
                    <TextLink href={editProfile()}>Settings → Profile</TextLink>
                    . We can end your access if you break these terms, if the
                    law requires it, or if we stop running /dev/null. The
                    sections on your content, disclaimers, liability and
                    governing law still apply after that.
                </p>
            </LegalSection>

            <LegalSection id="service" title="The service">
                <p>
                    /dev/null is provided “as is” and “as available”. We may
                    change, pause or stop any part of it. We aren't responsible
                    for websites and services that commits link to or embed.
                </p>
            </LegalSection>

            <LegalSection id="liability" title="Liability">
                <p>
                    As far as the law allows, we aren't liable for indirect or
                    consequential losses, or for lost profits, data or goodwill,
                    from using /dev/null. Nothing in these terms limits
                    liability that the law doesn't allow us to limit, or your
                    rights as a consumer.
                </p>
            </LegalSection>

            <LegalSection id="law" title="Governing law">
                <p>
                    These terms are governed by {legal.governingLaw}. Disputes
                    go to {legal.courts}, unless the law where you live gives
                    you the right to bring them there.
                </p>
            </LegalSection>

            <LegalSection id="changes" title="Changes to these terms">
                <p>
                    When we change these terms, we update the date at the top.
                    If a change matters, we'll tell you on /dev/null before it
                    takes effect. If you keep using /dev/null after that, you
                    accept the new terms.
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
