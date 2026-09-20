<?php
declare(strict_types=1);
namespace Tests\Fixtures\StaticControllerAction;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid')]
	public static function action(): void {}
}
