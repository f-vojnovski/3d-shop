<?php

namespace App\Jobs;

use App\Models\ProductFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A thumbnail is stored at whatever size the seller had, then shrunk here. A
 * listing card draws it about 300px wide, and a phone camera picture is several
 * megabytes, so the grid used to pull far more than it drew. Doing it in the
 * request would make the seller wait on work the upload does not need finished.
 */
class ScaleThumbnail implements ShouldQueue
{
    use Queueable;

    /** Enough for a card on a high-density screen, little enough to scroll. */
    public const EDGE = 512;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = ProductFile::find($this->fileId);

        if ($file === null
            || $file->kind !== ProductFile::KIND_THUMBNAIL
            || ($file->meta['scaled'] ?? false)) {
            return;
        }

        $original = Storage::disk($file->disk)->get($file->path);

        if ($original === null || $original === '') {
            Log::warning('Thumbnail not scaled: the stored object could not be read.', [
                'product_file_id' => $file->id,
            ]);

            return;
        }

        $scaled = self::scale($original);

        if ($scaled === null) {
            // Already card-sized, or not an image we can read. Either way it is
            // settled, and retrying would only read it again.
            $file->update(['meta' => array_replace($file->meta ?? [], ['scaled' => true])]);

            return;
        }

        $was = $file->path;
        $path = 'thumbnails/'.uniqid().'.'.$scaled['extension'];

        Storage::disk($file->disk)->put($path, $scaled['bytes']);

        $file->update([
            'path' => $path,
            'bytes' => strlen($scaled['bytes']),
            'checksum' => hash('sha256', $scaled['bytes']),
            'meta' => array_replace($file->meta ?? [], ['scaled' => true]),
        ]);

        // Written to a new name so the stored extension always matches the
        // bytes; the full-size original has nothing pointing at it now.
        Storage::disk($file->disk)->delete($was);
    }

    /**
     * @return array{bytes: string, extension: string}|null
     */
    private static function scale(string $original): ?array
    {
        $size = @getimagesizefromstring($original);
        $image = @imagecreatefromstring($original);

        if ($size === false || $image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::EDGE) {
            imagedestroy($image);

            return null;
        }

        $ratio = self::EDGE / $longest;
        $target = imagecreatetruecolor(
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio))
        );

        // A render is a transparent PNG, and a card on a dark page would
        // otherwise get a black rectangle where the backdrop should show.
        $keepsAlpha = $size[2] === IMAGETYPE_PNG;

        if ($keepsAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled(
            $target, $image,
            0, 0, 0, 0,
            imagesx($target), imagesy($target), $width, $height
        );

        ob_start();

        // A photograph stays a photograph: re-encoding one as PNG makes it
        // several times larger than the thing it replaced.
        $keepsAlpha ? imagepng($target, null, 8) : imagejpeg($target, null, 82);

        $bytes = (string) ob_get_clean();

        imagedestroy($image);
        imagedestroy($target);

        return $bytes === ''
            ? null
            : ['bytes' => $bytes, 'extension' => $keepsAlpha ? 'png' : 'jpg'];
    }
}
