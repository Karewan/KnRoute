<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Karewan\KnRoute\IMiddleware;

class EchoGlobalMiddleware implements IMiddleware
{
	public function before(): void
	{
		echo 'global-before-body';
	}

	public function after(): void {}
}
