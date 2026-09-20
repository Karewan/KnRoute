<?php

declare(strict_types=1);

use Karewan\KnRoute\Router;

const PROJECT_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Karewan\\KnRoute\\')) {
		$file = PROJECT_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen('Karewan\\KnRoute\\'))) . '.php';
		if (is_file($file)) require $file;
	}
});

$directory = sys_get_temp_dir() . '/knroute_cache_test_' . bin2hex(random_bytes(8));
$controllersPath = $directory . '/Controllers';
$cacheFile = $directory . '/routes.php';

if (!mkdir($controllersPath, 0770, true)) throw new RuntimeException('Unable to create cache test directory.');

try {
	$controllerFile = $controllersPath . '/CacheController.php';
	file_put_contents($controllerFile, <<<'PHP'
<?php
namespace Tests\CacheBehavior;
use Karewan\KnRoute\Attributes\Get;
class CacheController
{
	#[Get('/cache')]
	public function index(): void {}
}
PHP);

	spl_autoload_register(static function (string $class) use ($controllersPath): void {
		$prefix = 'Tests\\CacheBehavior\\';
		if (str_starts_with($class, $prefix)) {
			require $controllersPath . '/' . substr($class, strlen($prefix)) . '.php';
		}
	});

	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile);
	$initialCache = require $cacheFile;
	$initialSignature = $initialCache[4] ?? null;
	if (!is_string($initialSignature)) throw new RuntimeException('Controller signature is missing from cache.');
	if (($initialCache[5] ?? null) !== 2) throw new RuntimeException('Cache format version is missing from cache.');

	$initialCache[5] = 0;
	file_put_contents($cacheFile, '<?php return ' . var_export($initialCache, true) . ';');
	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile);
	if (((require $cacheFile)[5] ?? null) !== 2) throw new RuntimeException('An incompatible cache format was not regenerated.');

	file_put_contents($controllerFile, "\n", FILE_APPEND);
	clearstatcache(true, $controllerFile);
	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile, true);
	$modifiedSignature = (require $cacheFile)[4] ?? null;
	if ($modifiedSignature === $initialSignature) throw new RuntimeException('Controller edit did not invalidate cache.');

	unlink($controllerFile);
	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile, true);
	$deletedSignature = (require $cacheFile)[4] ?? null;
	if ($deletedSignature === $modifiedSignature) throw new RuntimeException('Controller deletion did not invalidate cache.');

	$addedControllerFile = $controllersPath . '/AddedController.php';
	file_put_contents($addedControllerFile, <<<'PHP'
<?php
namespace Tests\CacheBehavior;
use Karewan\KnRoute\Attributes\Get;
class AddedController
{
	#[Get('/added')]
	public function index(): void {}
}
PHP);
	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile, true);
	$addedSignature = (require $cacheFile)[4] ?? null;
	if ($addedSignature === $deletedSignature) throw new RuntimeException('Controller addition did not invalidate cache.');
	unlink($addedControllerFile);

	rmdir($controllersPath);
	(new Router())->registerRoutesFromControllers($controllersPath, $cacheFile, false);

	echo "PASS  Cache detects format changes, additions, edits and deletions and loads without scanning in production\n";
} finally {
	if (is_file($cacheFile)) unlink($cacheFile);
	if (is_dir($controllersPath)) rmdir($controllersPath);
	if (is_dir($directory)) rmdir($directory);
}
