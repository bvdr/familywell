<?php

namespace App\Support\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Psr\Http\Message\UriInterface;

/**
 * A file handed to an MCP tool. MCP has no upload primitive, so a tool accepts the file one of three ways:
 *  - `file`: {download_url, file_name?} — what ChatGPT passes for a file the person attached in the chat,
 *    when the tool lists the parameter in `_meta['openai/fileParams']` (see FILE_PARAMS_META);
 *  - `file_url`: any public https URL the assistant has;
 *  - `file_base64` + `file_name`: the raw bytes, for clients that can read local files (Claude Code, Cursor).
 * The result is an UploadedFile, so it goes through the same storage code as a browser upload.
 *
 * SECURITY: fetching a URL from the server is an SSRF risk (an assistant could be talked into pointing it
 * at internal hosts). So: https only, every hop's host must resolve to public addresses only, the first
 * connection is pinned to the address we checked (no DNS-rebinding swap), max 3 redirects, and the body
 * is read up to the size cap and no further. The type is decided by sniffing the bytes, not by the name.
 */
class IncomingFile
{
    /** Put on a tool as `protected ?array $meta = IncomingFile::FILE_PARAMS_META;` so ChatGPT passes files. */
    public const FILE_PARAMS_META = ['openai/fileParams' => ['file']];

    /** Sniffed MIME => extension, the document/receipt types the app already accepts. */
    public const DOCUMENTS = [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
    ];

    public const MAX_BYTES = 12 * 1024 * 1024; // 12 MB, same cap as the web forms

    /** Schema fragment for the three ways to pass a file. */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'file' => $schema->object([
                'download_url' => $schema->string(),
                'file_id' => $schema->string(),
                'file_name' => $schema->string(),
            ])->description('A file the person attached in the conversation (ChatGPT passes it here).'),
            'file_url' => $schema->string()->description('Or: a public https URL to download the file from.'),
            'file_base64' => $schema->string()->description('Or: the file content, base64-encoded (needs file_name).'),
            'file_name' => $schema->string()->description('File name, e.g. "deviz.pdf" (used with file_base64 or file_url).'),
        ];
    }

    /** The file passed to this tool call, or null when none was. Throws a ValidationException on a bad one. */
    public static function from(Request $request, array $types = self::DOCUMENTS, int $maxBytes = self::MAX_BYTES): ?UploadedFile
    {
        $file = $request->get('file');
        $url = is_array($file) ? ($file['download_url'] ?? null) : $request->get('file_url');
        $name = (is_array($file) ? ($file['file_name'] ?? null) : null) ?? $request->get('file_name');
        $b64 = $request->get('file_base64');

        if ($url) {
            $bytes = self::download((string) $url, $maxBytes);
            $name ??= basename((string) parse_url((string) $url, PHP_URL_PATH));
        } elseif ($b64) {
            $bytes = base64_decode((string) $b64, true);
            if ($bytes === false) {
                self::fail('file_base64 is not valid base64.');
            }
        } else {
            return null;
        }

        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            self::fail('The file is empty or larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset($types[$mime])) {
            self::fail("Unsupported file type ({$mime}). Allowed: ".implode(', ', array_unique($types)).'.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mcp');
        file_put_contents($path, $bytes);
        $base = pathinfo((string) $name, PATHINFO_FILENAME) ?: 'file';

        // test=true: the file did not arrive through PHP's upload mechanism, which is_uploaded_file() would reject.
        return new UploadedFile($path, $base.'.'.$types[$mime], $mime, null, true);
    }

    private static function download(string $url, int $maxBytes): string
    {
        $ip = self::publicAddress($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        try {
            $response = Http::timeout(30)->withOptions([
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]], // connect to the address we vetted
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'on_redirect' => fn ($req, $res, UriInterface $uri) => self::publicAddress((string) $uri),
                ],
            ])->get($url);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            self::fail('Could not download the file from that URL.');
        }
        if (! $response->successful()) {
            self::fail("Downloading the file failed (HTTP {$response->status()}).");
        }

        // Read at most one byte past the cap, so an oversized body is detected without loading all of it.
        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $body->eof() && strlen($bytes) <= $maxBytes) {
            $bytes .= $body->read(65536);
        }

        return $bytes;
    }

    /** The host's address if the URL is https and every address it resolves to is public; fails otherwise. */
    private static function publicAddress(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            self::fail('file_url must be an https URL.');
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $candidate) {
            if (! filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::fail('That URL points to a private network address.');
            }
        }
        if ($ips === []) {
            self::fail('Could not resolve the file URL host.');
        }

        return $ips[0];
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
