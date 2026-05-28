<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class FileUploadService
{
    /**
     * Store an uploaded file under the given context (logo, contract, import).
     *
     * Pipeline (every upload, no exceptions):
     *  1. Size check (config/uploads.php max_sizes)
     *  2. MIME check — extension AND real bytes via mime_content_type()
     *  3. UUID filename — never trust $file->getClientOriginalName()
     *  4. Raster images re-encoded as JPEG to strip EXIF / payloads
     *  5. SVG sanitised (script tags + event handlers stripped)
     *  6. Stored on the private disk (never public/, never storage/app/public/)
     *  7. Only the relative path is returned to callers
     */
    public function store(UploadedFile $file, string $context, array $options = []): string
    {
        $this->validateSize($file, $context, $options);
        $mime = $this->validateMime($file, $context);

        $filename = Str::uuid()->toString().'.'.$this->safeExtension($mime);
        $relativePath = $context.'/'.$filename;

        $contents = $this->sanitisedContents($file, $mime);

        Storage::disk('private')->put($relativePath, $contents);

        return $relativePath;
    }

    public function getSignedUrl(string $path, ?int $minutes = null): string
    {
        $minutes ??= (int) config('uploads.signed_url_minutes', 30);

        return Storage::disk('private')->temporaryUrl($path, now()->addMinutes($minutes));
    }

    public function delete(string $path): void
    {
        Storage::disk('private')->delete($path);
    }

    private function validateSize(UploadedFile $file, string $context, array $options): void
    {
        $max = (int) ($options['max_size']
            ?? config("uploads.max_sizes.$context")
            ?? config('uploads.max_sizes.default'));

        if ($file->getSize() > $max) {
            $mb = number_format($max / 1024 / 1024, 1);

            throw ValidationException::withMessages([
                'file' => "File exceeds the {$mb} MB limit for {$context} uploads.",
            ]);
        }
    }

    /**
     * Verify the file's actual byte signature matches the allow-list. The
     * client-reported MIME and extension are both untrusted; we use
     * `mime_content_type()` against the on-disk file to inspect real bytes.
     */
    private function validateMime(UploadedFile $file, string $context): string
    {
        $allowed = config("uploads.allowed_mimes.$context");

        if (! is_array($allowed) || $allowed === []) {
            throw ValidationException::withMessages([
                'file' => "Unknown upload context '{$context}'.",
            ]);
        }

        $realMime = mime_content_type($file->getPathname()) ?: '';
        $clientMime = $file->getClientMimeType();

        if (! in_array($realMime, $allowed, true) || ! in_array($clientMime, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => "File type not allowed for {$context}. Accepted: ".implode(', ', $allowed).'.',
            ]);
        }

        return $realMime;
    }

    private function safeExtension(string $mime): string
    {
        $map = (array) config('uploads.extension_for_mime', []);

        return $map[$mime] ?? 'bin';
    }

    /**
     * Strip EXIF / metadata for raster images; sanitise SVG; pass others through.
     */
    private function sanitisedContents(UploadedFile $file, string $mime): string
    {
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $manager = new ImageManager(new GdDriver());
            $image = $manager->decodePath($file->getPathname());

            return (string) $image->encode(new JpegEncoder(quality: 85));
        }

        if ($mime === 'image/svg+xml') {
            return $this->sanitiseSvg((string) file_get_contents($file->getPathname()));
        }

        return (string) file_get_contents($file->getPathname());
    }

    private function sanitiseSvg(string $svg): string
    {
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $svg) ?? $svg;
        $svg = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $svg) ?? $svg;
        $svg = preg_replace('#href\s*=\s*"javascript:[^"]*"#i', 'href=""', $svg) ?? $svg;

        return $svg;
    }
}
