<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class ImportedActivityBundle
{
    /**
     * @param  'fit_zip'|'fit'  $format
     */
    public function __construct(
        public NormalizedActivity $activity,
        public ActivityStreamData $stream,
        public string $rawBytes,
        public string $format,
    ) {}
}
