<?php

declare(strict_types=1);

namespace Modules\System\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\System\Services\BackupManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, BackupManager $manager, string $disk, string $filename): StreamedResponse
    {
        $backup = $manager->download($request->user(), $disk, $filename);

        return response()->streamDownload(function () use ($backup): void {
            $stream = $backup->stream();
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
