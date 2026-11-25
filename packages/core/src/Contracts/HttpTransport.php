<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;

interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}
