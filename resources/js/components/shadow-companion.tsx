import { router, usePage } from '@inertiajs/react';
import { Check, Lock, Palette, Terminal } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ShadowController from '@/actions/App/Http/Controllers/ShadowController';
import ShadowSkinController from '@/actions/App/Http/Controllers/ShadowSkinController';
import { ShadowSprite } from '@/components/shadow-sprite';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { Profile, ShadowData, ShadowSkin } from '@/types';

export const SHADOW_SKINS: {
    id: ShadowSkin;
    name: string;
    description: string;
    cost: number;
    colorClass: string;
    bgClass: string;
}[] = [
    {
        id: 'classic-green',
        name: 'Classic Green',
        description: 'Standard VT100 monochrome phosphor green',
        cost: 0,
        colorClass: 'text-emerald-500 dark:text-emerald-400',
        bgClass: 'bg-emerald-500/10 border-emerald-500/30',
    },
    {
        id: 'amber-crt',
        name: 'Amber CRT',
        description: 'Warm, low-fatigue amber phosphor CRT glow',
        cost: 50,
        colorClass: 'text-amber-500 dark:text-amber-400',
        bgClass: 'bg-amber-500/10 border-amber-500/30',
    },
    {
        id: 'matrix',
        name: 'Matrix Rain',
        description: 'High-contrast digital rain matrix stream',
        cost: 100,
        colorClass: 'text-green-500 dark:text-green-400',
        bgClass: 'bg-green-500/10 border-green-500/30',
    },
    {
        id: 'cyber-cyan',
        name: 'Cyber Cyan',
        description: 'Ultra-bright neon cyan cyberpunk pulse',
        cost: 150,
        colorClass: 'text-cyan-500 dark:text-cyan-400',
        bgClass: 'bg-cyan-500/10 border-cyan-500/30',
    },
    {
        id: 'kernel-red',
        name: 'Kernel Red',
        description: 'Root kernel panic ring reserved for elite processes',
        cost: 250,
        colorClass: 'text-rose-500 dark:text-rose-400',
        bgClass: 'bg-rose-500/10 border-rose-500/30',
    },
];

const GLYPH_PRESETS = ['λ', '◈', '▲', '⬢', '⌥', '⚙', '⚡', '⌘', '❖', '✦'];

export function ShadowCompanion({
    shadow,
    lane,
    isCurrentUser,
    user,
}: {
    shadow: ShadowData;
    lane: string;
    isCurrentUser: boolean;
    user: Profile;
}) {
    const { auth } = usePage().props;
    const [isCustomizeOpen, setIsCustomizeOpen] = useState(false);
    const [glyph, setGlyph] = useState(shadow.glyph);
    const [name, setName] = useState(shadow.name);
    const [isSaving, setIsSaving] = useState(false);
    const [unlockingSkin, setUnlockingSkin] = useState<ShadowSkin | null>(null);

    const currentSkinConfig =
        SHADOW_SKINS.find((s) => s.id === shadow.skin) ?? SHADOW_SKINS[0];

    const auraBorderColor =
        shadow.aura_color === 'gray'
            ? 'border-zinc-400 dark:border-zinc-500'
            : shadow.aura_color === 'cyan'
              ? 'border-cyan-500 shadow-[0_0_12px_rgba(6,182,212,0.3)]'
              : shadow.aura_color === 'blue'
                ? 'border-blue-500 shadow-[0_0_12px_rgba(59,130,246,0.3)]'
                : shadow.aura_color === 'magenta'
                  ? 'border-fuchsia-500 shadow-[0_0_12px_rgba(217,70,239,0.3)]'
                  : shadow.aura_color === 'yellow'
                    ? 'border-amber-400 shadow-[0_0_12px_rgba(251,191,36,0.35)]'
                    : 'border-rose-500 shadow-[0_0_14px_rgba(244,63,94,0.4)]';

    const handleEquipSkin = (skinId: ShadowSkin) => {
        setIsSaving(true);
        router.visit(ShadowController.update(), {
            data: { skin: skinId },
            preserveScroll: true,
            onFinish: () => setIsSaving(false),
            onSuccess: () => {
                toast.success('Shadow skin equipped.');
                router.flushAll();
            },
            onError: () => toast.error('Could not equip skin.'),
        });
    };

    const handleUnlockSkin = (skinId: ShadowSkin) => {
        setUnlockingSkin(skinId);
        router.visit(ShadowSkinController.store(skinId), {
            preserveScroll: true,
            onFinish: () => setUnlockingSkin(null),
            onSuccess: () => {
                toast.success('New skin unlocked with Bytes!');
                router.flushAll();
            },
            onError: (errors) => {
                const message =
                    errors.skin ||
                    Object.values(errors)[0] ||
                    'Insufficient Bytes to unlock this skin.';
                toast.error(message);
            },
        });
    };

    const handleSaveCosmetics = () => {
        setIsSaving(true);
        router.visit(ShadowController.update(), {
            data: {
                name: name.trim(),
                glyph: glyph.trim(),
            },
            preserveScroll: true,
            onFinish: () => setIsSaving(false),
            onSuccess: () => {
                toast.success('Shadow companion updated.');
                setIsCustomizeOpen(false);
                router.flushAll();
            },
            onError: (errors) => {
                const message =
                    errors.name ||
                    errors.glyph ||
                    'Could not update Shadow companion.';
                toast.error(message);
            },
        });
    };

    return (
        <section
            aria-label={`Developer Shadow Companion for @${user.username}`}
            className="border-b px-4 py-3 sm:px-6"
            data-test="shadow-companion-section"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    {/* Shadow Glyph Box with Rank Aura Ring */}
                    <div
                        className={cn(
                            'relative flex h-20 w-24 shrink-0 items-center justify-center rounded-lg border-2 font-mono transition-transform hover:scale-105',
                            currentSkinConfig.bgClass,
                            currentSkinConfig.colorClass,
                            auraBorderColor,
                        )}
                        title={`Companion: ${shadow.name} (${shadow.title}) · Rank Aura: ${shadow.aura_color}`}
                    >
                        <ShadowSprite
                            level={shadow.level}
                            glyph={shadow.glyph}
                            skin={shadow.skin}
                        />
                        <span
                            aria-hidden
                            className="absolute -top-1 -right-1 size-2 rounded-full"
                            style={{ backgroundColor: lane }}
                        />
                    </div>

                    <div className="flex flex-col">
                        <div className="flex items-center gap-2 font-mono text-sm">
                            <span className="font-semibold text-foreground">
                                {shadow.name}
                            </span>
                            <span className="py-0.2 rounded bg-muted px-1.5 font-sans text-[11px] text-muted-foreground">
                                {shadow.title}
                            </span>
                        </div>
                        <div className="flex items-center gap-2 font-mono text-xs text-muted-foreground">
                            <span>Skin: {currentSkinConfig.name}</span>
                            <span aria-hidden>·</span>
                            <span className="capitalize">
                                Aura: {shadow.aura_color} (Lv. {shadow.level})
                            </span>
                        </div>
                    </div>
                </div>

                {isCurrentUser && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setIsCustomizeOpen(true)}
                        className="gap-1.5 font-mono text-xs"
                    >
                        <Palette className="size-3.5" />
                        <span>Customize Shadow</span>
                    </Button>
                )}
            </div>

            {/* Customization Dialog */}
            {isCurrentUser && (
                <Dialog
                    open={isCustomizeOpen}
                    onOpenChange={setIsCustomizeOpen}
                >
                    <DialogContent className="max-w-md gap-4 sm:max-w-lg">
                        <DialogHeader className="gap-1.5">
                            <DialogTitle className="flex items-center gap-2 text-left font-mono">
                                <Terminal className="size-4 text-primary" />
                                <span>Shadow Companion Configuration</span>
                            </DialogTitle>
                            <DialogDescription className="text-left text-xs">
                                Your Shadow takes on a new form as your Unix
                                rank grows. Earn Bytes to unlock cosmetic skins
                                and make your companion your own.
                            </DialogDescription>
                        </DialogHeader>

                        {/* Name and Glyph Inputs */}
                        <div className="flex flex-col gap-3">
                            <div className="grid grid-cols-3 gap-2">
                                <div className="col-span-2 flex flex-col gap-1">
                                    <label
                                        htmlFor="shadow-name"
                                        className="font-mono text-xs text-muted-foreground"
                                    >
                                        Shadow Name:
                                    </label>
                                    <input
                                        id="shadow-name"
                                        type="text"
                                        value={name}
                                        maxLength={24}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        className="rounded-md border border-input bg-transparent px-2.5 py-1.5 font-mono text-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <label
                                        htmlFor="shadow-glyph"
                                        className="font-mono text-xs text-muted-foreground"
                                    >
                                        Glyph:
                                    </label>
                                    <input
                                        id="shadow-glyph"
                                        type="text"
                                        value={glyph}
                                        maxLength={4}
                                        onChange={(e) =>
                                            setGlyph(e.target.value)
                                        }
                                        className="rounded-md border border-input bg-transparent px-2.5 py-1.5 text-center font-mono text-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                    />
                                </div>
                            </div>

                            {/* Glyph Presets */}
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="font-mono text-[11px] text-muted-foreground">
                                    Presets:
                                </span>
                                {GLYPH_PRESETS.map((p) => (
                                    <button
                                        key={p}
                                        type="button"
                                        onClick={() => setGlyph(p)}
                                        className={cn(
                                            'size-6 rounded border font-mono text-xs transition-colors hover:border-primary',
                                            glyph === p
                                                ? 'border-primary bg-primary/20 font-bold text-primary'
                                                : 'border-border text-muted-foreground',
                                        )}
                                    >
                                        {p}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Skin Wardrobe */}
                        <div className="flex flex-col gap-2">
                            <div className="flex items-center justify-between font-mono text-xs">
                                <span className="font-medium">
                                    Skin Wardrobe
                                </span>
                                <span className="text-muted-foreground">
                                    Wallet: {auth.wallet?.balance ?? 0} Bytes
                                </span>
                            </div>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {SHADOW_SKINS.map((s) => {
                                    const isUnlocked =
                                        shadow.unlocked_skins.includes(s.id);
                                    const isEquipped = shadow.skin === s.id;
                                    const canUnlock =
                                        (auth.wallet?.balance ?? 0) >= s.cost;

                                    return (
                                        <div
                                            key={s.id}
                                            className={cn(
                                                'flex flex-col justify-between gap-1.5 rounded-md border p-2.5 text-xs',
                                                isEquipped
                                                    ? 'border-primary bg-primary/10'
                                                    : 'border-border/60 bg-card/60',
                                            )}
                                        >
                                            <div className="flex items-center justify-between">
                                                <span
                                                    className={cn(
                                                        'font-mono font-medium',
                                                        s.colorClass,
                                                    )}
                                                >
                                                    {s.name}
                                                </span>
                                                {isEquipped ? (
                                                    <span className="py-0.2 flex items-center gap-0.5 rounded bg-primary/20 px-1.5 font-mono text-[10px] text-primary">
                                                        <Check className="size-3" />{' '}
                                                        Equipped
                                                    </span>
                                                ) : isUnlocked ? (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={isSaving}
                                                        onClick={() =>
                                                            handleEquipSkin(
                                                                s.id,
                                                            )
                                                        }
                                                        className="h-6 px-2 font-mono text-[10px]"
                                                    >
                                                        Equip
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="secondary"
                                                        disabled={
                                                            !canUnlock ||
                                                            unlockingSkin ===
                                                                s.id
                                                        }
                                                        onClick={() =>
                                                            handleUnlockSkin(
                                                                s.id,
                                                            )
                                                        }
                                                        className="h-6 gap-1 px-2 font-mono text-[10px]"
                                                    >
                                                        <Lock className="size-2.5" />
                                                        <span>{s.cost} B</span>
                                                    </Button>
                                                )}
                                            </div>
                                            <p className="text-[11px] text-muted-foreground">
                                                {s.description}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setIsCustomizeOpen(false)}
                                disabled={isSaving}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={handleSaveCosmetics}
                                disabled={isSaving}
                                className="font-mono text-xs"
                            >
                                {isSaving ? 'Saving…' : 'Save Cosmetics'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}
