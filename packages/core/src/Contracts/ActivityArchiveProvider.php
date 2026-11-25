<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Data\ProviderContext;

interface ActivityArchiveProvider extends TelemetryProvider
{
    /**
     * Download raw archive bytes (typically .zip containing .fit, or raw .fit) for an external activity ID.
     *
     * @return array{bytes: string, format: 'fit_zip'|'fit'|'gpx'|'tcx'}
     */
    public function downloadActivityArchive(ProviderContext $context, string $externalActivityId): array;
}
