<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use Karewan\KnRoute\Attributes\Any;
use Karewan\KnRoute\Attributes\Delete;
use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Attributes\Head;
use Karewan\KnRoute\Attributes\Options;
use Karewan\KnRoute\Attributes\Post;
use Karewan\KnRoute\Attributes\Route;

class RoutingController
{
	#[Get('/static')]
	public function staticRoute(): void
	{
		echo 'static';
	}

	#[Get('/users/{id:num}')]
	public function user(string $id): void
	{
		echo "user:{$id}";
	}

	#[Delete('/resources/{id:num}')]
	public function deleteResource(string $id): void
	{
		echo "deleted:{$id}";
	}

	#[Any('/any')]
	public function anyMethod(): void
	{
		echo 'any';
	}

	#[Route(['GET', 'POST'], '/multiple')]
	public function multipleMethods(): void
	{
		echo 'multiple';
	}

	#[Head('/explicit-head')]
	public function explicitHead(): void
	{
		echo 'head';
	}

	#[Options('/explicit-options')]
	public function explicitOptions(): void
	{
		echo 'options';
	}

	#[Get('/typed/{id:num}')]
	public function typedInteger(int $id): void
	{
		echo get_debug_type($id) . ":{$id}";
	}

	#[Get('/variables/{alpha:alpha}/{letters:letters}/{slug:slug}/{hex:hex}/{value:any}')]
	public function variableTypes(string $alpha, string $letters, string $slug, string $hex, string $value): void
	{
		echo implode('|', [$alpha, $letters, $slug, $hex, $value]);
	}

	#[Get('/files/{path:all}')]
	public function catchAll(string $path): void
	{
		echo "file:{$path}";
	}

	#[Get('/alias-one')]
	#[Get('/alias-two')]
	public function aliases(): void
	{
		echo 'alias';
	}

	#[Get('/method-specific')]
	public function methodSpecificGet(): void
	{
		echo 'get';
	}

	#[Post('/method-specific')]
	public function methodSpecificPost(): void
	{
		echo 'post';
	}

	#[Post('/post-only')]
	public function postOnly(): void
	{
		echo 'post-only';
	}

	#[Any('/priority')]
	public function priorityFallback(): void
	{
		echo 'fallback';
	}

	#[Get('/priority')]
	public function priorityGet(): void
	{
		echo 'explicit';
	}

	#[Route(['PURGE'], '/custom-method')]
	public function customMethod(): void
	{
		echo 'purged';
	}
}
