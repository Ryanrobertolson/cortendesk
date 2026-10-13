<?php

namespace App\Services\Backups;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * S3 and S3-compatible storage (MinIO, Backblaze B2, Wasabi, Cloudflare R2,
 * Hetzner, ...) over plain HTTP with Signature Version 4. Written against the
 * REST API directly: the AWS SDK is tens of megabytes for three verbs.
 *
 * The payload is signed by its SHA-256, so an upload is streamed from disk and
 * never held in memory.
 */
final class S3Target implements RemoteTarget
{
    private string $endpoint;

    private string $prefix;

    private string $region;

    public function __construct(
        private string $bucket,
        string $region,
        private string $accessKey,
        private string $secretKey,
        string $endpoint = '',
        string $prefix = '',
        private bool $pathStyle = false,
    ) {
        $this->region = trim($region) !== '' ? trim($region) : 'us-east-1';
        $this->endpoint = rtrim(trim($endpoint) !== '' ? trim($endpoint) : "https://s3.{$this->region}.amazonaws.com", '/');
        $prefix = trim($prefix, '/ ');
        $this->prefix = $prefix === '' ? '' : $prefix.'/';
        $this->bucket = trim($bucket);
    }

    public function label(): string
    {
        return 'S3 bucket '.$this->bucket.($this->prefix !== '' ? '/'.rtrim($this->prefix, '/') : '');
    }

    public function put(string $localPath, string $name): void
    {
        $this->request('PUT', $this->prefix.$name, [], $localPath);
    }

    public function list(): array
    {
        $names = [];
        $token = null;

        do {
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($this->prefix !== '') {
                $query['prefix'] = $this->prefix;
            }
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $xml = $this->xml($this->request('GET', '', $query));

            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;
                if ($this->prefix !== '' && ! str_starts_with($key, $this->prefix)) {
                    continue;
                }
                $rest = substr($key, strlen($this->prefix));
                // Only direct children: a key with another slash lives in a
                // sub-directory that is not ours to manage.
                if ($rest === '' || str_contains($rest, '/')) {
                    continue;
                }
                $names[$rest] = isset($object->Size) ? (int) $object->Size : null;
            }

            $token = ((string) ($xml->IsTruncated ?? '')) === 'true'
                ? (string) $xml->NextContinuationToken
                : null;
        } while ($token !== null && $token !== '');

        return $names;
    }

    public function delete(string $name): void
    {
        $this->request('DELETE', $this->prefix.$name);
    }

    public function test(): string
    {
        $query = ['list-type' => '2', 'max-keys' => '1'];
        if ($this->prefix !== '') {
            $query['prefix'] = $this->prefix;
        }
        $this->request('GET', '', $query);

        return 'Connected to '.$this->label().'.';
    }

    /**
     * @param  array<string, string>  $query
     */
    private function request(string $method, string $key, array $query = [], ?string $bodyFile = null): Response
    {
        $parts = parse_url($this->endpoint);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('The S3 endpoint is not a valid URL.');
        }
        if ($this->bucket === '') {
            throw new RuntimeException('The S3 bucket name is empty.');
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = rtrim($parts['path'] ?? '', '/');

        if ($this->pathStyle) {
            $path = $basePath.'/'.$this->bucket.'/'.$key;
        } else {
            $host = $this->bucket.'.'.$host;
            $path = $basePath.'/'.$key;
        }

        // Each segment encoded on its own so the slashes survive.
        $canonicalUri = implode('/', array_map('rawurlencode', explode('/', $path)));
        if ($canonicalUri === '') {
            $canonicalUri = '/';
        }

        ksort($query);
        $canonicalQuery = implode('&', array_map(
            fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v),
            array_keys($query),
            $query,
        ));

        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $payloadHash = $bodyFile !== null ? (string) hash_file('sha256', $bodyFile) : hash('sha256', '');

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        ksort($headers);
        $canonicalHeaders = implode('', array_map(fn ($k, $v) => $k.':'.trim($v)."\n", array_keys($headers), $headers));
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method, $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash,
        ]);

        $scope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $scheme = $parts['scheme'] ?? 'https';
        $url = "{$scheme}://{$host}{$canonicalUri}".($canonicalQuery !== '' ? '?'.$canonicalQuery : '');

        $pending = Http::withHeaders([
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ])->connectTimeout(15)->timeout($bodyFile !== null ? 3600 : 60);

        if ($bodyFile !== null) {
            $stream = fopen($bodyFile, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Cannot read {$bodyFile}.");
            }
            $pending = $pending->withBody(Utils::streamFor($stream), 'application/x-tar');
        }

        try {
            $response = $pending->send($method, $url);
        } catch (\Throwable $e) {
            throw new RuntimeException('S3 request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    private function xml(Response $response): SimpleXMLElement
    {
        $xml = @simplexml_load_string($response->body());
        if ($xml === false) {
            throw new RuntimeException('S3 returned a response that is not XML.');
        }

        return $xml;
    }

    private function errorMessage(Response $response): string
    {
        $status = $response->status();
        $detail = '';
        $xml = @simplexml_load_string($response->body());
        if ($xml !== false) {
            $code = (string) ($xml->Code ?? '');
            $message = (string) ($xml->Message ?? '');
            $detail = trim($code.($message !== '' ? ': '.$message : ''));
        }

        return "S3 returned HTTP {$status}".($detail !== '' ? " ({$detail})" : '').'.';
    }
}
