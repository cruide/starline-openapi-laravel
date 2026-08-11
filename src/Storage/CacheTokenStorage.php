<?php namespace Cruide\StarlineLaravel\Storage;

use Cruide\StarlineApi\Auth\TokenStorageInterface;
use Illuminate\Contracts\Cache\Repository;

class CacheTokenStorage implements TokenStorageInterface
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = '',
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

    public function delete(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    public function flush(): void
    {
        foreach (['starline.app_token', 'starline.user_token', 'starline.slnet', 'starline.user_id'] as $key) {
            $this->delete($key);
        }
    }

    private function key(string $key): string
    {
        return $this->prefix !== '' ? $this->prefix . '.' . $key : $key;
    }
}
