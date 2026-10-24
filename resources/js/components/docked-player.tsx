import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { PlayerFrame } from '@/components/link-embed';
import { VideoDockControls } from '@/components/video-dock-controls';
import { useDockedPlayer } from '@/hooks/use-docked-player';
import { cn } from '@/lib/utils';
import type { DockedVideo } from '@/lib/video-dock';
import { canGoFullscreen, goFullscreen } from '@/lib/video-player-state';

const controlsDelay = 1500;

/**
 * The one playing video's player, laid over its embed or floating in a corner. Loaded only once a video is played,
 * so pages that never play one don't carry it.
 */
export default function DockedPlayer({ video }: { video: DockedVideo }) {
    const { autoPictureInPicture } = usePage().props;
    const player = useDockedPlayer(video, autoPictureInPicture);
    const [isFullscreenSupported] = useState(canGoFullscreen);
    const [isShowingControls, setIsShowingControls] = useState(false);
    const hideControls = useRef<number | null>(null);

    useEffect(
        () => () => {
            if (hideControls.current !== null) {
                window.clearTimeout(hideControls.current);
            }
        },
        [],
    );

    const showControls = () => {
        if (hideControls.current !== null) {
            window.clearTimeout(hideControls.current);
            hideControls.current = null;
        }

        setIsShowingControls(true);
    };

    const scheduleHidingControls = () => {
        if (hideControls.current !== null) {
            window.clearTimeout(hideControls.current);
        }

        hideControls.current = window.setTimeout(() => {
            hideControls.current = null;
            setIsShowingControls(false);
        }, controlsDelay);
    };

    return (
        <div
            ref={player.wrapper}
            role={player.isFloating ? 'region' : undefined}
            aria-label={player.isFloating ? 'Video player' : undefined}
            onPointerEnter={showControls}
            onPointerLeave={scheduleHidingControls}
            className={cn(
                // In its post it belongs under the sticky headers like the rest of the page. The player moves itself
                // to the layer over them once it floats and has come out from under them.
                'group/dock invisible fixed top-0 left-0 z-1 will-change-transform',
                player.isFloating && '[view-transition-name:video-dock]',
            )}
            data-test="video-dock"
            data-floating={player.isFloating}
        >
            <div
                ref={player.surface}
                className={cn('relative size-full overflow-hidden bg-black', {
                    'rounded-xl shadow-2xl ring-1 ring-white/10':
                        player.isFloating,
                })}
            >
                <PlayerFrame
                    ref={player.frame}
                    src={player.src}
                    title={video.title}
                    className={cn('h-full bg-black', {
                        'pointer-events-none': player.isInteracting,
                    })}
                />
            </div>

            {player.isFloating && (
                <VideoDockControls
                    isActive={isShowingControls || player.isInteracting}
                    isInteracting={player.isInteracting}
                    isFullscreenSupported={isFullscreenSupported}
                    onClose={player.close}
                    onFullscreen={() =>
                        player.frame.current &&
                        goFullscreen(player.frame.current)
                    }
                    onStartDrag={player.startDrag}
                    onStartResize={player.startResize}
                    onMove={player.move}
                    onEnd={player.endInteraction}
                />
            )}
        </div>
    );
}
