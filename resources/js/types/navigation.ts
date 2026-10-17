import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type NavItem = {
    title: string;
    /**
     * Shown on hover, for items whose name alone may not say what they are.
     */
    description?: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    hasIndicator?: boolean;
    badge?: number;
    prefetch?: boolean;
};
