<?php

declare(strict_types=1);
use App\Middleware\Trace;
use Arix\Application;

return Application::create(dirname(__DIR__))->kernel([Trace::class]);
