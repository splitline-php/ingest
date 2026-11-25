<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Data\ProviderContext;

interface CredentialProvider
{
    public function getAccessToken(ProviderContext $context, bool $forceRefresh = false): string;

    public function forgetToken(ProviderContext $context): void;
}
