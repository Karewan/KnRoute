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

	#[Get('/head-fallback')]
	public function headFallback(): void
	{
		\Karewan\KnRoute\header('X-Head-Fallback: executed');
		echo 'get body';
	}

	#[Get('/users/{id:uint}')]
	public function user(string $id): void
	{
		echo "user:{$id}";
	}

	#[Get('/lookup/{id:uint}')]
	public function lookupById(string $id): void
	{
		echo "lookup-id:{$id}";
	}

	#[Get('/lookup/{name:alpha}')]
	public function lookupByName(string $name): void
	{
		echo "lookup-name:{$name}";
	}

	#[Delete('/resources/{id:uint}')]
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

	#[Get('/typed/{id:uint}')]
	public function typedInteger(int $id): void
	{
		echo get_debug_type($id) . ":{$id}";
	}

	#[Get('/typed-scalars/{integer:int}/{decimal:segment}/{flag:segment}/{text:segment}/{raw:segment}')]
	public function typedScalars(int $integer, float $decimal, bool $flag, string $text, $raw): void
	{
		echo implode('|', [
			get_debug_type($integer) . ":{$integer}",
			get_debug_type($decimal) . ":{$decimal}",
			get_debug_type($flag) . ':' . ($flag ? 'true' : 'false'),
			get_debug_type($text) . ":{$text}",
			get_debug_type($raw) . ":{$raw}",
		]);
	}

	#[Get('/variables/{first:alpha}/{second:alpha}/{slug:slug}/{hex:hex}/{value:segment}')]
	public function variableTypes(string $first, string $second, string $slug, string $hex, string $value): void
	{
		echo implode('|', [$first, $second, $slug, $hex, $value]);
	}

	#[Get('/strict-variables/{alnum:alnum}/{signed:int}/{unsigned:uint}/{uuid:uuid}/{segment:segment}/{path:path}')]
	public function strictVariableTypes(string $alnum, string $signed, string $unsigned, string $uuid, string $segment, string $path): void
	{
		echo implode('|', [$alnum, $signed, $unsigned, $uuid, $segment, $path]);
	}

	#[Get('/files/{path:path}')]
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
