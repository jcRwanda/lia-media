import { Camera, ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

interface ProofUploaderProps {
    label?: string;
    files: File[];
    onChange: (files: File[]) => void;
    maxFiles?: number;
    required?: boolean;
    helpText?: string;
}

export function ProofUploader({ label = 'Photo proof', files, onChange, maxFiles = 10, required = true, helpText }: ProofUploaderProps) {
    const cameraRef = useRef<HTMLInputElement>(null);
    const galleryRef = useRef<HTMLInputElement>(null);
    const [previews, setPreviews] = useState<{ file: File; url: string }[]>([]);

    useEffect(() => {
        const next = files.map((file) => ({ file, url: URL.createObjectURL(file) }));
        setPreviews(next);

        return () => {
            next.forEach((preview) => URL.revokeObjectURL(preview.url));
        };
    }, [files]);

    const addFiles = (incoming: FileList | null) => {
        if (!incoming || incoming.length === 0) return;
        const next = [...files];
        for (const file of Array.from(incoming)) {
            if (!file.type.startsWith('image/')) continue;
            if (next.length >= maxFiles) break;
            next.push(file);
        }
        onChange(next);
    };

    const removeFile = (index: number) => {
        onChange(files.filter((_, i) => i !== index));
    };

    return (
        <div className="space-y-2">
            <div className="text-xs font-medium text-neutral-600">
                {label}
                {required ? ' (required, at least one image)' : ''}
            </div>
            {helpText ? <div className="text-[11px] text-neutral-500">{helpText}</div> : null}
            <div className="flex flex-wrap gap-2">
                <input ref={cameraRef} type="file" accept="image/*" capture="environment" className="hidden" onChange={(e) => addFiles(e.target.files)} />
                <input ref={galleryRef} type="file" accept="image/*" multiple className="hidden" onChange={(e) => addFiles(e.target.files)} />
                <Button type="button" variant="secondary" size="sm" onClick={() => cameraRef.current?.click()}>
                    <Camera className="h-4 w-4" /> Take photo
                </Button>
                <Button type="button" variant="secondary" size="sm" onClick={() => galleryRef.current?.click()}>
                    <ImagePlus className="h-4 w-4" /> Choose images
                </Button>
                <span className="self-center text-xs text-neutral-500">{files.length}/{maxFiles} attached</span>
            </div>
            {previews.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                    {previews.map((preview, index) => (
                        <div key={preview.url} className="relative">
                            <img src={preview.url} alt={`${label} ${index + 1}`} className="h-20 w-20 rounded-lg border object-cover" />
                            <button
                                type="button"
                                aria-label="Remove photo"
                                className="absolute -right-1.5 -top-1.5 rounded-full bg-white p-0.5 shadow ring-1 ring-neutral-200"
                                onClick={() => removeFile(index)}
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
