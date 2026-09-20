<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;
use Tests\Fixtures\Values\ExportablePolicy;
use Tests\Fixtures\Values\Role;

#[Attribute(Attribute::TARGET_METHOD)]
class ArgumentMiddleware implements IMiddleware
{
	public function __construct(
		private readonly Role $role,
		private readonly ExportablePolicy $policy
	) {
	}

	public function handle(): void
	{
		echo strtolower($this->role->name) . ':' . $this->policy->name . '>';
	}
}
