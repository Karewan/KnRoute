<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

interface IMiddleware
{
	public function before(): void;

	public function after(): void;
}
