<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

use function strlen;

class BoundedDownload
{
    /** @var resource */
    private $stream;

    private int $bytes = 0;

    public function __construct(string $destination, private readonly int $maxBytes)
    {
        if ($maxBytes < 1 || is_link($destination)) {
            throw new RuntimeException('Unsafe download destination or invalid size limit.');
        }

        $stream = @fopen($destination, 'wb');

        if ($stream === false) {
            throw new RuntimeException("Cannot write download: {$destination}. Check filesystem permissions.");
        }

        $this->stream = $stream;
    }

    public function write(string $data): int
    {
        $length = strlen($data);

        if ($length > $this->maxBytes - $this->bytes) {
            throw new RuntimeException('Download exceeds the size limit.');
        }

        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($this->stream, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write download. Check disk space and permissions.');
            }

            $offset += $written;
        }

        $this->bytes += $length;

        return $length;
    }

    public function close(): void
    {
        fclose($this->stream);
    }
}
