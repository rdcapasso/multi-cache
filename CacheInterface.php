<?php
/**
 * This is an interface for a caching class.
 *
 * @package Multi-Cache
 * @author Robert Capasso <rdcapasso@gmail.com>
 */
interface CacheInterface
{
    /**
     * Returns the cached data if it exists and is not expired.
     *
     * @abstract
     * @param string $cacheKey the cache key for the data.
     */
    public function get($cacheKey);

    /**
     * Adds data to the cache.
     *
     * @abstract
     * @param string $cacheKey string the cache key for the data.
     * @param mixed $data mixed the data to be stored.
     * @param int $expires int the expiration time.
     */
    public function set($cacheKey, $data, $expires = 0);

    /**
     * Removes a key/value pair from the cache.
     *
     * @abstract
     * @param string $cacheKey the cache key for the data.
     */
    public function expire($cacheKey);

    /**
     * Returns information about the cached data.
     *
     * @abstract
     * @param string $cacheKey the cache key for the data.
     */
    public function read($cacheKey);

    /**
     * Returns the maximum size of the cache.
     *
     * @abstract
     */
    public function getCacheMaxSize();

    /**
     * Returns the current size of the cache.
     *
     * @abstract
     */
    public function getCacheSize();

    /**
     * Returns the type of cache implementation.
     *
     * @abstract
     */
    public function getCacheType();

    /**
     * Empties the cache.
     *
     * @abstract
     */
    public function flushCache();

}
