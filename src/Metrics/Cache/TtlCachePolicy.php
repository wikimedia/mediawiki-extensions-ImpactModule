<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\ImpactModule\Metrics\Cache;

class TtlCachePolicy extends CachePolicy {

	private array $cacheKeyComponents = [];
	private ?int $cacheVersion = null;

	protected function __construct(
		private readonly int $ttlInSeconds
	) {
	}

	public function getCacheVersion(): ?int {
		return $this->cacheVersion;
	}

	public function getTTLInSeconds(): int {
		return $this->ttlInSeconds;
	}

	public function getCacheKeyComponents(): array {
		return $this->cacheKeyComponents;
	}

	public function setCacheKeyComponents( string|int ...$cacheKeyComponents ): CachePolicy {
		$this->cacheKeyComponents = $cacheKeyComponents;
		return $this;
	}

	public function setCacheVersion( int $cacheVersion ): CachePolicy {
		$this->cacheVersion = $cacheVersion;
		return $this;
	}
}
