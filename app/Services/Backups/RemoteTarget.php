<?php

namespace App\Services\Backups;

/**
 * Somewhere off the box a finished backup is copied to. Every method throws a
 * RuntimeException with an operator-readable message on failure; nothing here
 * retries, the scheduler runs again tomorrow and the local copy is kept.
 */
interface RemoteTarget
{
    /** Upload a local file under the given name. */
    public function put(string $localPath, string $name): void;

    /**
     * Names of the files present at the target, directory prefix stripped.
     *
     * @return list<string>
     */
    public function list(): array;

    public function delete(string $name): void;

    /** Prove the credentials and location work; returns a one-line summary. */
    public function test(): string;

    /** For log lines and the UI: "S3 bucket backups" / "FTP host:21". */
    public function label(): string;
}
