import { Head } from '@inertiajs/react';
import type { ComponentProps, PropsWithChildren } from 'react';
import { legal } from '@/lib/legal';
import { cn } from '@/lib/utils';

export function LegalDocument({
    title,
    children,
}: PropsWithChildren<{ title: string }>) {
    return (
        <>
            <Head title={title} />

            <div className="sticky top-0 z-10 flex h-14 items-center border-b bg-card px-4 sm:px-6">
                <h1 className="font-semibold">{title}</h1>
            </div>

            <article className="space-y-8 px-4 py-6 text-[15px] leading-relaxed text-pretty sm:px-6">
                <p className="text-sm text-muted-foreground">
                    Last updated {legal.updatedAt}
                </p>
                {children}
            </article>
        </>
    );
}

export function LegalSection({
    id,
    title,
    children,
}: PropsWithChildren<{ id: string; title: string }>) {
    return (
        <section aria-labelledby={id} className="scroll-mt-20 space-y-3">
            <h2 id={id} className="text-lg font-semibold tracking-tight">
                {title}
            </h2>
            {children}
        </section>
    );
}

export function LegalList({ children }: PropsWithChildren) {
    return <ul className="space-y-2">{children}</ul>;
}

export function LegalItem({ children }: PropsWithChildren) {
    return (
        <li className="flex gap-2.5">
            <span
                aria-hidden
                className="mt-[0.7em] size-1.5 shrink-0 rounded-full bg-rail"
            />
            <span className="min-w-0">{children}</span>
        </li>
    );
}

export function LegalLink({ className, ...props }: ComponentProps<'a'>) {
    return (
        <a
            className={cn(
                '[overflow-wrap:anywhere] underline decoration-muted-foreground/50 underline-offset-4 hover:decoration-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function ContactLink() {
    return (
        <LegalLink href={`mailto:${legal.contactEmail}`}>
            {legal.contactEmail}
        </LegalLink>
    );
}
