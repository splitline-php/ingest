<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Data\ImportedActivityBundle;

interface FileImportProvider extends TelemetryProvider
{
    /**
     * Lowercase extensions without dot (e.g. ['fit', 'zip']).
     *
     * @return list<string>
     */
    public function supportedExtensions(): array;

    public function importBytes(
        string $bytes,
        string $filename,
        string $timezone = 'UTC',
    ): ImportedActivityBundle;
}
