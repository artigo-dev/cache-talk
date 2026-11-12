<?php
declare(strict_types=1);

namespace Stores;

use Ovos\Store;
use Ovos\Store\Mysql\Traits\CacheService;
use Talk\Origin;

/**
 * The demo site's monthly report: the expensive page every visitor of the
 * dashboard asks for, cached the way any php-library store caches - one
 * get() with a resolver. Which store answers it, and with or without a lock,
 * is the `cache:` configuration's business, not this code's.
 */
class Reports extends Store
{
	use CacheService;
	
	public const string TABLE = 'reports';
	
	public function __construct()
	{
		parent::__construct();
		
		$this->initCacheService();
	}
	
	/**
	 * @return array{what: string, by: int}
	 */
	public function monthly(
		string $month,
	): array
	{
		return $this->cacheService->getStore()->get(
			'report-' . $month,
			fn() => Origin::work('report ' . $month),
			ttl: 3600,
			tags: ['reports'],
		);
	}
	
	/**
	 * The store built, connected and its functions loaded, so that the first
	 * measured call pays for none of it
	 */
	public function connect(): void
	{
		$this->cacheService->getStore()->get('report-warm-up');
	}
}
