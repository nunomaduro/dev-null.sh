import { Link } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { cn } from '@/lib/utils';
import { show as showPrivacy } from '@/routes/privacy';
import { show as showTerms } from '@/routes/terms';

export function SignInAgreement({ className }: { className?: string }) {
    return (
        <p
            className={cn(
                'text-sm text-pretty text-muted-foreground',
                className,
            )}
        >
            By signing in, you agree to the{' '}
            <TextLink href={showTerms()}>terms</TextLink> and{' '}
            <TextLink href={showPrivacy()}>privacy policy</TextLink>.
        </p>
    );
}

export function LegalLinks({ className }: { className?: string }) {
    return (
        <nav aria-label="Legal" className={cn('flex gap-4', className)}>
            <Link
                href={showTerms()}
                className="rounded-sm transition-colors hover:text-foreground"
            >
                Terms
            </Link>
            <Link
                href={showPrivacy()}
                className="rounded-sm transition-colors hover:text-foreground"
            >
                Privacy
            </Link>
        </nav>
    );
}
