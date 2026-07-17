// Shrink a picked image to <= maxBytes before upload: smaller storage, fast uploads, and we never
// hit PHP's post/upload limits. Uses the browser canvas (no dependency). Non-images pass through
// untouched, and if anything in the pipeline fails we fall back to the original file rather than
// blocking the upload.
export async function compressImage(file: File, maxBytes = 1_000_000): Promise<File> {
    if (!file.type.startsWith('image/') || file.size <= maxBytes) return file;

    const bitmap = await loadBitmap(file);
    if (!bitmap) return file;

    // Start from a sane ceiling on the longest side; a receipt never needs more than this.
    let maxSide = 2560;
    for (let attempt = 0; attempt < 8; attempt++) {
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        if (!ctx) return file;
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        // Walk JPEG quality down first (cheaper than re-scaling), then drop dimensions and retry.
        for (const quality of [0.82, 0.7, 0.6, 0.5]) {
            const blob = await toBlob(canvas, quality);
            if (blob && blob.size <= maxBytes) {
                return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
            }
        }
        maxSide = Math.round(maxSide * 0.8);
    }
    return file;
}

async function loadBitmap(file: File): Promise<HTMLImageElement | null> {
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        await new Promise((resolve, reject) => {
            img.onload = resolve;
            img.onerror = reject;
            img.src = url;
        });
        return img;
    } catch {
        return null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

function toBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}
