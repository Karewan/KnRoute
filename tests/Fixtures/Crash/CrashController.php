<?php

declare(strict_types=1);

namespace Tests\Fixtures\Crash;

use Karewan\KnRoute\Attributes\Get;
use RuntimeException;

class CrashController
{
	#[Get('/crash')]
	public function crash(): void
	{
		\Tests\MiddlewareLifecycle::$events[] = 'action';
		throw new RuntimeException('action crash');
	}
}
