<?php

namespace App\Services\Backups;

use FTP\Connection;
use RuntimeException;

/**
 * Plain FTP or explicit FTPS (AUTH TLS) through PHP's ftp extension, always
 * passive. SFTP is a different protocol and is not supported here.
 */
final class FtpTarget implements RemoteTarget
{
    private string $path;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        string $path = '',
        private bool $tls = false,
    ) {
        $this->host = trim($host);
        $this->path = trim($path, '/ ');
        $this->port = $port > 0 ? $port : 21;
    }

    public function label(): string
    {
        return ($this->tls ? 'FTPS ' : 'FTP ').$this->host.':'.$this->port.($this->path !== '' ? '/'.$this->path : '');
    }

    public function put(string $localPath, string $name): void
    {
        $conn = $this->connect();
        try {
            if (! @ftp_put($conn, $name, $localPath, FTP_BINARY)) {
                throw new RuntimeException("FTP upload of {$name} failed.");
            }
        } finally {
            ftp_close($conn);
        }
    }

    public function list(): array
    {
        $conn = $this->connect();
        try {
            $entries = @ftp_nlist($conn, '.');
            if ($entries === false) {
                throw new RuntimeException('FTP directory listing failed.');
            }

            // Servers differ on whether NLST returns bare names or paths.
            return array_values(array_filter(
                array_map(fn ($e) => basename((string) $e), $entries),
                fn ($n) => $n !== '' && $n !== '.' && $n !== '..',
            ));
        } finally {
            ftp_close($conn);
        }
    }

    public function delete(string $name): void
    {
        $conn = $this->connect();
        try {
            if (! @ftp_delete($conn, $name)) {
                throw new RuntimeException("FTP delete of {$name} failed.");
            }
        } finally {
            ftp_close($conn);
        }
    }

    public function test(): string
    {
        $conn = $this->connect();
        try {
            $pwd = @ftp_pwd($conn);

            return 'Connected to '.$this->label().($pwd !== false ? ", directory {$pwd}" : '').'.';
        } finally {
            ftp_close($conn);
        }
    }

    private function connect(): Connection
    {
        if (! function_exists('ftp_connect')) {
            throw new RuntimeException('PHP\'s ftp extension is not installed on this server.');
        }
        if ($this->tls && ! function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('PHP\'s ftp extension was built without TLS support (--with-ftp-ssl), so FTPS is not available on this server.');
        }
        if ($this->host === '') {
            throw new RuntimeException('The FTP host is empty.');
        }

        $conn = $this->tls
            ? @ftp_ssl_connect($this->host, $this->port, 15)
            : @ftp_connect($this->host, $this->port, 15);

        if ($conn === false) {
            throw new RuntimeException("Cannot reach {$this->host}:{$this->port} over ".($this->tls ? 'FTPS' : 'FTP').'.');
        }

        if (! @ftp_login($conn, $this->username, $this->password)) {
            ftp_close($conn);
            throw new RuntimeException('The FTP server refused the username or password.');
        }

        @ftp_pasv($conn, true);

        if ($this->path !== '') {
            // Walk the path one directory at a time, creating what is missing,
            // so a fresh server needs no preparation.
            foreach (explode('/', $this->path) as $segment) {
                if (@ftp_chdir($conn, $segment)) {
                    continue;
                }
                if (! @ftp_mkdir($conn, $segment) || ! @ftp_chdir($conn, $segment)) {
                    ftp_close($conn);
                    throw new RuntimeException("Cannot enter or create the FTP directory /{$this->path}.");
                }
            }
        }

        return $conn;
    }
}
