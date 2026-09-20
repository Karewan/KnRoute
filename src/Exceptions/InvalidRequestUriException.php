<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

final class InvalidRequestUriException extends HttpException
{
	public function __construct()
	{
		parent::__construct(400, 'The request URI is invalid.');
	}
}
