<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * Streams a plugin archive to a file. Unlike core's HTTP client it allows https only, on the
 * first request and on every redirect (so a redirect cannot reach http:// or file:// targets),
 * and it stops once the archive passes the size cap instead of buffering the whole body.
 */
class ArchiveDownloader
{
    private const MAX_REDIRECTS = 5;
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly int $maxBytes = PluginArchive::MAX_ARCHIVE_BYTES,
        private readonly int $protocols = CURLPROTO_HTTPS,
    ) {
    }

    /** Download $url to $dest, or throw and leave no file behind. */
    public function fetch(string $url, string $dest): void
    {
        $out = fopen($dest, 'wb');
        if ($out === false) {
            throw new ModMenuException(t('Unable to download the plugin archive.'));
        }

        $written = 0;
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS      => $this->protocols,
            CURLOPT_REDIR_PROTOCOLS => $this->protocols,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_USERAGENT      => 'Kanboard ModMenu',
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use ($out, &$written, &$tooLarge): int {
                $written += strlen($chunk);
                if ($written > $this->maxBytes) {
                    $tooLarge = true;
                    return 0; // a short write aborts the transfer
                }
                return (int) fwrite($out, $chunk);
            },
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($out);

        if ($ok === true && $written > 0) {
            return;
        }
        @unlink($dest);
        throw new ModMenuException($tooLarge
            ? t('The plugin archive is too large (over %d MB).', intdiv($this->maxBytes, 1048576) ?: 1)
            : t('Unable to download the plugin archive.'));
    }
}
