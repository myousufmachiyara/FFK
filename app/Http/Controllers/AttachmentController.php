<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Serves any file stored on the 'public' disk directly through
     * Laravel, instead of relying on the public/storage symlink being
     * served as a static file by the web server.
     */
    public function show(string $path): StreamedResponse
    {
        abort_unless(Storage::disk('public')->exists($path), 404, 'Attachment not found.');

        return Storage::disk('public')->response($path);
    }
}
