<?php

declare(strict_types=1);

namespace Tests\Fixtures\StopRequest;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS)]
class TraceMiddleware implements IMiddleware
{
	public function before(): void
	{
		echo 'trace>';
	}

	public function after(): void
	{
		echo '>trace-after';
	}
}
