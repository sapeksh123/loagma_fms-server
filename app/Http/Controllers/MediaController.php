<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /**
     * GET /uploads/{path}
     *
     * Serves uploaded files (product/category images) from storage/app/uploads.
     * Deliberately routed through Laravel rather than served as a static file
     * under public/ — PHP's built-in dev server (`php artisan serve`) serves
     * existing public/ files directly, bypassing all middleware, so those
     * responses never picked up the app's CORS headers. Storing uploads
     * outside the public docroot forces every request through this route,
     * where the global Cors middleware (bootstrap/app.php) applies normally.
     */
    public function serve(string $path): BinaryFileResponse
    {
        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '..')) {
            abort(404);
        }

        $fullPath = storage_path('app/uploads/' . $normalized);

        if (!is_file($fullPath)) {
            abort(404);
        }

        return response()->file($fullPath);
    }
}
