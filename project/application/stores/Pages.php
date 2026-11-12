<?php
declare(strict_types=1);

namespace Stores;

use Ovos\Cache\Store\KeyValue;
use Ovos\Store;
use Ovos\Store\Mysql\Traits\CacheService;

/**
 * The demo site's cached pages, kept by the store the station's configuration
 * picks (`cache.persistent.store`). The scenarios fill it and forget it; no
 * page is built here.
 */
class Pages extends Store
{
	use CacheService;
	
	public const string TABLE = 'pages';
	
	public function __construct()
	{
		parent::__construct();
		
		$this->initCacheService();
	}
	
	public function store(): KeyValue
	{
		return $this->cacheService->getStore();
	}
}
