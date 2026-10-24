import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Lightbox from 'yet-another-react-lightbox';
import type { ControllerRef, LightboxProps } from 'yet-another-react-lightbox';
import Counter from 'yet-another-react-lightbox/plugins/counter';
import Download from 'yet-another-react-lightbox/plugins/download';
import Zoom from 'yet-another-react-lightbox/plugins/zoom';
import 'yet-another-react-lightbox/plugins/counter.css';
import 'yet-another-react-lightbox/styles.css';
import { commitImageAlt } from '@/components/commit-images';
import type { ShownImage } from '@/components/commit-images';

export function ImageViewer({
    images,
    index,
    onView,
    onClose,
    downloadUrl,
    downloadFilename,
    className,
    styles,
    toolbarButtons = [],
    controls,
}: {
    images: ShownImage[];
    index: number | null;
    onView: (index: number) => void;
    onClose: () => void;
    downloadUrl?: (image: ShownImage) => string;
    downloadFilename?: (image: ShownImage, index: number) => string;
    className?: string;
    styles?: LightboxProps['styles'];
    toolbarButtons?: ReactNode[];
    controls?: () => ReactNode;
}) {
    const [captionedImageId, setCaptionedImageId] = useState<string | null>(
        null,
    );
    const controller = useRef<ControllerRef>(null);
    const isOpen = index !== null;

    useEffect(() => {
        if (!isOpen || typeof document === 'undefined') {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            const lightbox = controller.current;
            const actions: Record<string, (() => void) | undefined> = {
                Escape: lightbox?.close,
                ArrowLeft: lightbox?.prev,
                ArrowRight: lightbox?.next,
            };

            if (
                document.activeElement === document.body &&
                actions[event.key]
            ) {
                event.preventDefault();
                actions[event.key]?.();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [isOpen]);

    const slides = useMemo(
        () =>
            images.map((image, imageIndex, all) => ({
                src: image.url,
                alt: commitImageAlt(image, imageIndex, all.length),
                width: image.width,
                height: image.height,
                ...(downloadUrl && {
                    download: {
                        url: downloadUrl(image),
                        filename:
                            downloadFilename?.(image, imageIndex) ??
                            `${image.id}.webp`,
                    },
                }),
            })),
        [images, downloadUrl, downloadFilename],
    );

    const hasMultipleImages = slides.length > 1;

    const plugins = useMemo(() => {
        const list = hasMultipleImages ? [Counter, Zoom] : [Zoom];

        if (downloadUrl) {
            list.push(Download);
        }

        return list;
    }, [hasMultipleImages, downloadUrl]);

    return (
        <Lightbox
            open={isOpen}
            close={onClose}
            index={index ?? 0}
            slides={slides}
            on={{
                view: ({ index: viewedIndex }) => {
                    if (isOpen && viewedIndex !== index) {
                        onView(viewedIndex);
                    }
                },
            }}
            plugins={plugins}
            className={className}
            styles={{
                ...styles,
                root: { '--yarl__portal_zindex': 50, ...styles?.root },
                container: {
                    backgroundColor: 'rgb(0 0 0 / 0.85)',
                    backdropFilter: 'blur(4px)',
                    ...styles?.container,
                },
            }}
            carousel={{ finite: true }}
            controller={{
                ref: controller,
                closeOnBackdropClick: true,
                closeOnPullDown: true,
            }}
            toolbar={{ buttons: [...toolbarButtons, 'close'] }}
            render={{
                ...(!hasMultipleImages && {
                    buttonPrev: () => null,
                    buttonNext: () => null,
                }),
                slideFooter: ({ slide }) => {
                    const image = images.find(({ url }) => url === slide.src);

                    if (!image?.description) {
                        return null;
                    }

                    const isCaptioned = captionedImageId === image.id;
                    const captionId = `image-caption-${image.id}`;

                    return (
                        <div
                            className="absolute inset-x-4 bottom-4 flex flex-col items-start gap-2"
                            onPointerDown={(event) => event.stopPropagation()}
                            onWheel={(event) => event.stopPropagation()}
                        >
                            {isCaptioned && (
                                <p
                                    id={captionId}
                                    className="max-h-40 max-w-xl touch-auto overflow-y-auto overscroll-contain rounded-lg bg-black/80 px-3 py-2 text-sm leading-relaxed whitespace-pre-line text-white ring-1 ring-white/15 select-text"
                                    data-test="image-caption"
                                >
                                    {image.description}
                                </p>
                            )}
                            <button
                                type="button"
                                onClick={() =>
                                    setCaptionedImageId(
                                        isCaptioned ? null : image.id,
                                    )
                                }
                                aria-expanded={isCaptioned}
                                aria-controls={captionId}
                                aria-label={
                                    isCaptioned
                                        ? 'Hide image description'
                                        : 'Show image description'
                                }
                                className="rounded bg-black/75 px-1.5 py-0.5 text-xs font-bold tracking-wide text-white ring-1 ring-white/30 transition hover:bg-black"
                            >
                                ALT
                            </button>
                        </div>
                    );
                },
                controls,
            }}
        />
    );
}
