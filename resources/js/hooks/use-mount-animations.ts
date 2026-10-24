import { useLayoutEffect, useRef } from 'react';

/**
 * Plays the Web Animations that `play` starts, once, as the component mounts (before paint, so nothing flashes in its
 * final state first), and cancels them when it unmounts. Under reduced motion nothing plays. `play` is read from the
 * first render, so key the component to play its entrance again.
 */
export function useMountAnimations(
    play: () => (Animation | undefined)[],
): void {
    const start = useRef(play);

    useLayoutEffect(() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const animations = start.current();

        return () => animations.forEach((animation) => animation?.cancel());
    }, []);
}
