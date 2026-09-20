<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableDuplicate;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{id:uint}/{id:hex}')] public function route(): void {} }
