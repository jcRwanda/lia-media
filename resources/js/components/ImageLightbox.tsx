import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';

/** Full-screen photo slider: arrows, swipe and keyboard (←/→/Esc). */
export function ImageLightbox({ images, start = 0, title, onClose }: { images: string[]; start?: number; title?: string; onClose: () => void }) {
    const [i, setI] = useState(start);
    const x0 = useRef<number | null>(null);
    const n = images.length;
    const step = (d: number) => setI((v) => (v + d + n) % n);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowLeft') step(-1);
            if (e.key === 'ArrowRight') step(1);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [n, onClose]);

    if (!n) return null;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
            role="dialog"
            aria-modal="true"
            onClick={(e) => e.target === e.currentTarget && onClose()}
            onTouchStart={(e) => (x0.current = e.touches[0].clientX)}
            onTouchEnd={(e) => {
                if (x0.current === null) return;
                const dx = e.changedTouches[0].clientX - x0.current;
                if (Math.abs(dx) > 40) step(dx < 0 ? 1 : -1);
                x0.current = null;
            }}
        >
            <button type="button" aria-label="Close" className="absolute right-4 top-4 rounded-full bg-white/20 p-2 text-white" onClick={onClose}>
                <X className="h-5 w-5" />
            </button>
            {n > 1 ? (
                <button type="button" aria-label="Previous" className="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-white/20 p-2 text-white" onClick={() => step(-1)}>
                    <ChevronLeft className="h-6 w-6" />
                </button>
            ) : null}
            <img src={images[i]} alt={title || ''} className="max-h-[85vh] max-w-full select-none rounded object-contain" />
            {n > 1 ? (
                <button type="button" aria-label="Next" className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-white/20 p-2 text-white" onClick={() => step(1)}>
                    <ChevronRight className="h-6 w-6" />
                </button>
            ) : null}
            <div className="absolute bottom-4 left-0 right-0 text-center text-sm text-white">
                {title}
                {n > 1 ? ` · ${i + 1} / ${n}` : ''}
            </div>
        </div>
    );
}
