<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Karewan\KnRoute\IMiddleware;

class SecondGlobalMiddleware implements IMiddleware
{
	public function before(): void
	{
		GlobalMiddleware::$executionOrder .= ',second';
		\Karewan\KnRoute\header('X-Global-Order: ' . GlobalMiddleware::$executionOrder);
	}

	public function after(): void {}
}
