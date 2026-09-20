<?php
declare(strict_types=1);
namespace Tests\Fixtures\MissingVariableParameter;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items')] public function route(string $id): void {} }
