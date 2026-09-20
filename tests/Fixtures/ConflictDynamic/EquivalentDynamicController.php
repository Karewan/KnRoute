<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictDynamic;

use Karewan\KnRoute\Attributes\Get;

class EquivalentDynamicController
{
	#[Get('/users/{id:num}')]
	public function byId(string $id): void {}

	#[Get('/users/{userId:num}')]
	public function byUserId(string $userId): void {}
}
