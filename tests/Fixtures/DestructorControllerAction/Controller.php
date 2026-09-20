<?php
declare(strict_types=1);
namespace Tests\Fixtures\DestructorControllerAction;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid')]
	public function __destruct() {}
}
