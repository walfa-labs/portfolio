<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream the owner's uploaded CV from the private bucket (§9.7).
 *
 * The CV is the one private object on the site: it lives on the `s3-private`
 * disk and is never linked directly — the app mediates every byte, with an
 * `attachment` disposition so the browser downloads rather than renders it.
 *
 * The row is scoped to the requesting user's own processed `cv` row (pinned
 * decision 14): `latest('id')` across every `cv` row would let any
 * authenticated user download the owner's CV, and an undefined disk would
 * throw before the 404 below. A guest — no requesting user — is a 404, the
 * same honest answer as a CV that has not been uploaded yet.
 */
class CvDownloadController extends Controller
{
    /**
     * The `media` collection that holds the CV (§9.1).
     */
    private const COLLECTION = 'cv';

    /**
     * The morph alias of the CV's owner (§9.2).
     */
    private const MEDIABLE_TYPE = 'user';

    /**
     * The one `media.status` a downloadable row carries (§9.2).
     */
    private const STATUS_PROCESSED = 'processed';

    /**
     * Handle the incoming request.
     *
     * A 404 is the honest answer when nothing has been uploaded yet: the route
     * is public and there is simply no object to serve.
     */
    public function __invoke(): StreamedResponse
    {
        $media = Media::query()
            ->where('collection', self::COLLECTION)
            ->where('mediable_type', self::MEDIABLE_TYPE)
            ->where('mediable_id', auth()->user()?->getAuthIdentifier())
            ->where('status', self::STATUS_PROCESSED)
            ->latest('id')
            ->firstOrFail();

        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        return $disk->download($media->path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
