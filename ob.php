<?php namespace ob;

# Something to do with output buffering.

# Usage:
# Start caching output buffer
// \ob\Buffer::getInstance()->registerHandler(new \ob\CacheHandler)->start();

/**
 * Output bufferring - \ob\Buffer
 */
class Buffer {
    protected $handlers; # Output buffering handlers
    private $buffer = ''; # Buffer contents

    /**
     * Register an output buffering callback
     * @param callable $handler The output handler to add
     * @param string $key Optional name for the handler for future removal
     */
    function registerHandler(callable $handler, $key = null) {
        if($key) $this->handlers[$key] = $handler;
        else $this->handlers[] = $handler;
        return $this;
    }

    /**
     * Remove a named handler
     * @param string $key Handler name
     */
    function unregisterHandler($key) {
        unset($this->handlers[$key]);
    }

    /**
     * Returns registered handlers
     */
    function getRegisteredHandlers() {
        return $this->handlers;
    }

    /**
     * Main handler, which processes content and passes it to
     * all additional registered handlers.
     */
    function __invoke($content, $bits) {
        if(!($bits & \PHP_OUTPUT_HANDLER_END)) {
            $this->buffer .= $content;
            return '';
        }
        $local = $this->buffer.$content;
        $this->buffer = '';
        foreach($this->handlers as $handler) {
            $local = $handler($local);
        }
        return $local;
    }

    /**
     * Start handling output
     */
    function start() {
        ob_start($this);
    }

    /**
     * Kill all active output buffers
     */
    static function kill($flush = false, $times = 10) {
        while(--$times && ob_get_level()) {
            if($flush) ob_end_flush();
            else ob_end_clean();
        }
    }
}

/**
 * Cache handler for `ob` class. - \ob\CacheHandler
 */
class CacheHandler {
    function isCacheable() {
        $code = http_response_code();
        # or in_array($code, [200, 203, 300, 301, 302, 404, 410])
        return !headers_sent() && !in_array($code, [201, 204, 304]);
    }

    function getLastModified() {
        $mtime = false;
        try {
            clearstatcache(true);
            if($file = getenv('SCRIPT_FILENAME')) {
                $mtime = filemtime($file);
            }
        } catch(\Exception $e) {}
        return $mtime;
    }

    function isHit($key, $val) {
        if(isset($_SERVER[$key])) {
            $client = strtolower($_SERVER[$key]);
            $client = preg_split('/\s*[,;]\s*/', $client, \PREG_SPLIT_NO_EMPTY);
            if(empty($client)) $client = ['MISS'];
            return in_array($val, $client);
        }
        return false;
    }

    function __invoke($local) {
        if($this->isCacheable()) {
            $local_md5 = md5($local);
            $last_mod  = $this->getLastModified();
            header('Content-MD5: '.$local_md5);
            if($this->isHit('HTTP_IF_NONE_MATCH', $local_md5)
            || $this->isHit('HTTP_IF_MODIFIED_SINCE', $last_mod)) {
                http_response_code(304);
                $local = '';
            } else {
                $last_mod = date(\DateTime::RFC2822, $last_mod);
                header('ETag: '.$local_md5);
                header('Last-Modified: '.$last_mod);
            }
        }
        return $local;
    }
}
