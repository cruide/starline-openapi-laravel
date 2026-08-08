<?php namespace StarlineApi\Storage;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stores SLID tokens in the Laravel cache so the full auth chain
 * is not repeated on every request.
 */
class CacheTokenStorage
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'starline',
        private readonly int $ttl = 86400,
    ) {
    }

    public function get(string $key): ?string
    {
        $value = $this->cache->get($this->key($key));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        $this->cache->put($this->key($key), $value, $ttl ?? $this->ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    /** Remove every token stored by this package. */
    public function flush(): void
    {
        foreach (['app_token', 'slid_token', 'user_id', 'slnet'] as $key) {
            $this->forget($key);
        }
    }

    private function key(string $key): string
    {
        return $this->prefix.'.'.$key;
    }
}