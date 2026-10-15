import type { ReactNode } from 'react';

export type AppLayoutProps = {
    children: ReactNode;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type CommittedFlash = {
    id: string;
    body: string;
};

export type OmarchyTheme = {
    name: string;
    mode: 'light' | 'dark';
    font: string | null;
};
