export type CommitGiftType =
    | 'coffee'
    | 'beer'
    | 'overclock'
    | 'rocket'
    | 'root'
    | 'signal'
    | 'phoenix'
    | 'eclipse';

export type CommitGiftInfo = {
    type: CommitGiftType;
    label: string;
    icon: string;
    cost: number;
    description: string;
    ascii?: string;
};

export type ShadowSkin =
    | 'classic-green'
    | 'amber-crt'
    | 'matrix'
    | 'cyber-cyan'
    | 'kernel-red';

export type ShadowData = {
    name: string;
    glyph: string;
    skin: ShadowSkin;
    title: string;
    unlocked_skins: ShadowSkin[];
    rank: string;
    level: number;
    aura_color: string;
};

export type WalletData = {
    balance: number;
    lifetime_earned: number;
    lifetime_tipped: number;
    has_claimed_today: boolean;
    today_allocated: number | null;
};
