<?php

namespace App\Http\Controllers;

use App\Models\ConsoleAudit;
use App\Services\Backups\BackupService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function download(string $name, BackupService $backups): BinaryFileResponse
    {
        $path = $backups->path($name);
        abort_if($path === null, 404);

        ConsoleAudit::record('backup.download', 'Downloaded backup '.$name, 'backup', $name);

        return response()->download($path, $name, ['Content-Type' => 'application/x-tar']);
    }
}
