<?php
/**
 * FileCache class.
 *
 * This class implements CacheInterface for caching data using the file system.
 * @package Multi-Cache
 * @author Robert Capasso <rdcapasso@gmail.com>
 */
class FileCache implements CacheInterface
{

    //object variables.
    private $cache_dir = "cache";
    private $use_compression = false;
    private $cache_contents = array();
    private $max_size = 67108864; //64MB
    private $cache_contents_filename;

    function __construct($cache_dir = null, $use_compression = false, $max_size = null)
    {

        //set the directory for caching
        if ($cache_dir != null && trim($cache_dir) != "")
            $this->cache_dir = $cache_dir;

        //set filename for cache contents file.
        $this->cache_contents_filename = $this->cache_dir . "/cache_contents.txt";

        //is the cache directory writeable?
        if (!$this->isWritable()) {
            //if we can't write to the directory we can't do any caching, so throw an exception.
            Throw new FileCacheException("Could not write to cache directory!");
        }

        //do we want to compress the cached files?
        if ($use_compression !== false) {
            if (!$this->hasZLib()) {
                //if the user wants compression but zlib isn't installed that is exception-worthy.
                Throw new FileCacheException("ZLib not installed!  Cannot use file compression!");
            } else {
                $this->use_compression = $use_compression;
            }
        }

        //set max cache size.
        if ($max_size != null)
            $this->max_size = $max_size;

        //get cache contents array from disk.
        $test = $this->getCacheContents();
        if ($test !== null) {
            $this->cache_contents = $test;
        }

    }

    function __destruct()
    {
        //save cache_contents array to disk.
        $this->setCacheContents();
    }

    /**
     * grabs data from the cache and returns it to the user.
     * if data is expired or invalid, returns null.
     *
     * @param string $cacheKey the cache key to set.
     * @return mixed|null
     * @throws FileCacheException
     */
    public function get($cacheKey)
    {
        //make sure key exists in the array.
        $returnVal = null;
        $isValid = $this->isValid($cacheKey);

        if ($isValid === true) {
            $file_contents = "";

            $filename = $this->getFileName($cacheKey);

            //if the cache dir doesn't exist for some reason...
            if (!is_dir($this->cache_dir))
                Throw new FileCacheException("Could not read cache directory!");

            if (is_file($filename)) {
                $file_contents = file_get_contents($filename);

                if ($this->use_compression)
                    $file_contents = gzinflate($file_contents);

                $returnVal = unserialize($file_contents);
            }
        } else if ($isValid == -1) {
            $this->expire($cacheKey);
        }

        return $returnVal;
    }

    /**
     * adds data to the cache.
     * @TODO: add exception for failure to write file.
     *
     * @param string $cacheKey the cache key to set.
     * @param mixed $data the data to store.
     * @param int $expires expiration time in seconds.
     * @return bool|int
     * @throws FileCacheException
     */
    public function set($cacheKey, $data, $expires = 0)
    {
        //init return variable.
        $ret = true;

        //see if key exists in the array.  If so expire it.
        $isValid = $this->isValid($cacheKey);
        if ($isValid !== false) {
            $this->expire($cacheKey);
            $ret = -1;
        }

        //time to expire.
        $expiretime = ($expires != 0 ? strtotime("+" . $expires . " seconds") : -1);

        //set filename.
        $filename = $this->getFileName($cacheKey);

        //prepare the data for storage.
        $data = serialize($data);
        if ($this->use_compression)
            $data = gzdeflate($data);

        //if the cacheSize would go over maxCacheSize, expire all the old stuff.
        $currentCacheSize = $this->getCacheSize();
        if (($currentCacheSize + strlen($data)) > $this->getCacheMaxSize()) {
            $this->freshen();
        }

        //if this is still too big, throw an exception.
        if (($currentCacheSize + strlen($data)) > $this->getCacheMaxSize()) {
            Throw new FileCacheException("Cache is full!  Remove some cached items or increase the max size.");
        }

        //open our filename for writing and write.
        $fp = fopen($filename, "w");
        if ($fp === false)
            throw new FileCacheException("Could not open cache file!");

        $fw = fwrite($fp, $data);
        if ($fw === false)
            throw new FileCacheException("Could not write cache file!");

        fclose($fp);

        //add our new item to the cache_contents table.
        $this->cache_contents[$cacheKey] = $expiretime;

        return $ret;
    }


    /**
     * removes an item from the cache.
     *
     * @param string $cacheKey the cache key to set.
     * @return bool true on success, false on failure to remove.
     */
    public function expire($cacheKey)
    {
        //initialize return var.
        $ret = true;

        //if the key exists, remove the file.  else return false.
        if ($this->isValid($cacheKey) !== false) {
            //delete the file
            $filename = $this->getFileName($cacheKey);
            unlink($filename);

            //remove the key from the array
            unset($this->cache_contents[$cacheKey]);
        } else {
            $ret = false;
        }

        return $ret;
    }

    /**
     * returns basic information about a cached item.
     *
     * @param string $cacheKey the cache key to set.
     * @return array
     */
    public function read($cacheKey)
    {
        $returnVal = null;

        //check if our key is valid.
        $isValid = $this->isValid($cacheKey);

        //if the object is in the cache, build some info to return.
        if ($isValid !== false) {
            //return some basic info about the cached object.. expire time, size on disk.
            $returnVal = array("expires" => $this->cache_contents[$cacheKey],
                "size" => filesize($this->getFileName($cacheKey)),
                "dirname" => $this->cache_dir,
                "filename" => $this->getFileName($cacheKey, false)
            );
        }

        return $returnVal;
    }

    /**
     * returns the maximum size of the cache in bytes.
     *
     * @return int|null
     */
    public function getCacheMaxSize()
    {
        //return the maximum size of the cache
        return $this->max_size;
    }

    /**
     * returns the current size of the cache in bytes.
     *
     * @return string
     */
    public function getCacheSize()
    {
        //return the current size of the cache in kilobytes
        $io = popen('/usr/bin/du -sk ' . $this->cache_dir, 'r');
        $size = fgets($io, 4096);
        $size = (strpos($size, "\t") > -1 ? substr($size, 0, strpos($size, "\t")) : strpos($size, " "));
        pclose($io);
        return $size;
    }

    /**
     * returns the type of cache
     *
     * @return string
     */
    public function getCacheType()
    {
        //return the type of the cache class.
        return "FileCache";
    }

    /**
     * removes all elements in the cache.
     *
     */
    public function flushCache()
    {
        //expire all cached elements.
        $cache_key_arr = array_keys($this->cache_contents);
        foreach ($cache_key_arr as $cacheKey) {
            $this->expire($cacheKey);
        }
    }

    /**
     * Steps through the cache contents array and expires anything with an isValid() return of -1.
     */
    private function freshen()
    {
        //step through the cache array, remove all expired elements
        $cache_key_arr = array_keys($this->cache_contents);
        foreach ($cache_key_arr as $cacheKey) {
            if ($this->isValid($cacheKey) == -1) {
                $this->expire($cacheKey);
            }
        }
    }

    /**
     * Grabs serialized cache_contents array from the file on disk and returns the array.
     *
     * @return mixed|null
     */
    private function getCacheContents()
    {
        //initialize return var.
        $ret = null;

        //see if the file exists.
        if (is_file($this->cache_contents_filename)) {
            //get contents and unserialize.
            $ret = unserialize(file_get_contents($this->cache_contents_filename));
        }

        return $ret;
    }

    /**
     * Returns the filename of the cache item.
     *
     * @param string $cacheKey
     * @param bool $fullPath
     * @return string
     */
    private function getFileName($cacheKey, $fullPath = true)
    {
        //initialize return var.
        $ret = "";

        //do we want the full path or just the filename?
        if ($fullPath !== true) {
            $ret = $cacheKey . ".cache" . ($this->use_compression !== false ? ".gz" : "");
        } else {
            $ret = $this->cache_dir . "/" . $cacheKey . ".cache" . ($this->use_compression !== false ? ".gz" : "");
        }

        return $ret;
    }

    /**
     * tests to see if PHP has zlib support for compression.
     *
     * @return bool
     */
    private function hasZLib()
    {
        return function_exists("gzdeflate");
    }


    /**
     * checks to see if the passed in cache key is a valid key.  returns true if key exists and is valid,
     * returns false if key does not exist, returns -1 if key exists and has expired.
     *
     * @param string $cacheKey the cache key to set.
     * @return bool|int
     */
    private function isValid($cacheKey)
    {
        $returnVal = false;

        if (array_key_exists($cacheKey, $this->cache_contents)) {
            $exptime = $this->cache_contents[$cacheKey];
            if ($exptime > time()) {
                $returnVal = true;
            } else {
                $returnVal = -1;
            }
        }

        return $returnVal;
    }

    /**
     * checks to see if the cache directory can be written to.
     *
     * @todo: figure out file permissions that are more intelligent than 777.
     * @return bool
     */
    private function isWritable()
    {
        $returnVal = false;

        //if the cache directory doesn't exist
        if (!is_dir($this->cache_dir)) {
            if (is_writable(getcwd())) {
                mkdir($this->cache_dir);
                chmod($this->cache_dir, "go+rwx");
                $returnVal = is_writable($this->cache_dir);
            }
        } else {
            $returnVal = true;
        }

        return $returnVal;
    }

    /**
     * Serializes and saves cache_contents array to a file on disk.
     */
    private function setCacheContents()
    {
        //serialize and write to the cache contents file.
        file_put_contents($this->cache_contents_filename, serialize($this->cache_contents));
    }


}

?>