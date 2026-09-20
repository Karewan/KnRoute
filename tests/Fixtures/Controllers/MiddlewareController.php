<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use Karewan\KnRoute\Attributes\Get;
use Tests\Fixtures\Middlewares\ArgumentMiddleware;
use Tests\Fixtures\Middlewares\ClassMiddleware;
use Tests\Fixtures\Middlewares\MethodMiddleware;
use Tests\Fixtures\Values\ExportablePolicy;
use Tests\Fixtures\Values\Role;

#[ClassMiddleware]
class MiddlewareController
{
	#[Get('/middleware/class')]
	public function classMiddleware(): void
	{
		echo 'controller';
	}

	#[Get('/middleware/both'), MethodMiddleware]
	public function classAndMethodMiddlewares(): void
	{
		echo 'controller';
	}

	#[Get('/middleware/arguments'), ArgumentMiddleware(Role::Admin, new ExportablePolicy('managed'))]
	public function middlewareArguments(): void
	{
		echo 'controller';
	}

	#[Get('/middleware/typed/{id:uint}')]
	public function typedMiddleware(int $id): void
	{
		echo "controller:{$id}";
	}
}
