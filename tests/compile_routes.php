<?php

declare(strict_types=1);

use Karewan\KnRoute\Router;

const PROJECT_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
	$prefixes = [
		'Karewan\\KnRoute\\' => PROJECT_ROOT . '/src/',
		'Tests\\Fixtures\\' => __DIR__ . '/Fixtures/'
	];

	foreach ($prefixes as $prefix => $directory) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require $file;
		return;
	}
});

try {
	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/' . $argv[1], null);
} catch (Throwable $e) {
	echo json_encode(['class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
	exit(0);
}

exit(1);
