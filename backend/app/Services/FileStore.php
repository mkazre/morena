<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin wrapper over the configured filesystem disk (FILESYSTEM_DISK: "local" in dev, "s3" in production
 * per handover §1 — never local disk in production).
 */
class FileStore
{
    public const ALLOWED_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    public const MAX_KB = 10240; // 10MB, matches the client upload page copy

    public function put(UploadedFile $file, string $folder): string
    {
        return $file->store($folder);
    }

    public function download(string $path, string $name): StreamedResponse
    {
        abort_unless(Storage::exists($path), 404, 'File not found.');

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filename = str_ends_with(strtolower($name), '.'.$ext) ? $name : $name.'.'.$ext;

        return Storage::download($path, $filename);
    }
}
