<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Karewan\KnRoute\IMiddleware;

class GlobalMiddleware implements IMiddleware
{
	public static string $executionOrder = '';

	public function handle(): void
	{
		self::$executionOrder .= 'first';
		\Karewan\KnRoute\header('X-Global-Middleware: true');
	}
}
