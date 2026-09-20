<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Karewan\KnRoute\IMiddleware;

class SecondGlobalMiddleware implements IMiddleware
{
	public function handle(): void
	{
		GlobalMiddleware::$executionOrder .= ',second';
		\Karewan\KnRoute\header('X-Global-Order: ' . GlobalMiddleware::$executionOrder);
	}
}
