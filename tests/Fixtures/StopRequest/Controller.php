<?php

declare(strict_types=1);

namespace Tests\Fixtures\StopRequest;

use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Exceptions\StopRequestException;

#[TraceMiddleware]
class Controller
{
	#[Get('/stop/middleware'), StopMiddleware]
	public function stoppedByMiddleware(): void
	{
		echo 'ACTION-MUST-NOT-RUN';
	}

	#[Get('/stop/action')]
	public function stoppedByAction(): void
	{
		echo 'action';
		throw new StopRequestException();
	}
}
