<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableMissingType;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{id}')] public function route(): void {} }
