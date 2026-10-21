<?php

namespace App\Http\Controllers;

use App\Models\AdmissionApplication;
use App\Models\ApplicationDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationDocumentController extends Controller
{
    public function download(AdmissionApplication $application, ApplicationDocument $document): StreamedResponse
    {
        abort_unless($document->application_id === $application->id, 404);
        Gate::authorize('downloadDocument', $application);
        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->storage_key), 404);

        return $disk->download($document->storage_key, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
