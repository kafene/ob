<?php

namespace kafene\ob;

# kafene\ob - Something to do with output buffering.
# Give it a last modified time, and it will automatically
# generate an ETag header for all your page's output, a
# Last-Modified header, and send caching headers to the client.
# If the If-None-Match or If-Modified-Since are matched then
# it will send a 304 Not Modified and Shut. Down. Everything.
#
# Note - not sure if this works with zlib output, but I think it does...
#
# ob_start(new \kafene\ob\CacheHandler);
# License - Public Domain. See <http://unlicense.org/>.
# Usage is simple:
#   - ob_start(http_cache_buffer());
# Or with a custom "last modified time" (Default is ~1 month, btw):
#   - ob_start(http_cache_buffer(strtotime('+1 week')));

/*
# Example:
ob_start(http_cache_buffer());
print 'I am from Scotland!';
# */
// -> check your browser's inspector/network log:
//
// -> Expires: Wed, 07 Aug 2013 20:31:48 GMT
// -> Cache-Control: max-age=2592000, public
// -> Content-MD5: d41d8cd98f00b204e9800998ecf8427e
// -> Content-Length: 19
// -> Pragma: Public
// -> ETag: "d41d8cd98f00b204e9800998ecf8427e"
// -> Last-Modified: Sat, 08 Jun 2013 20:31:48 GMT

# TTL is the time to request the page be cached for.
function http_cache_buffer($ttl = 2592000) {
    static $_buffer = '';
    static $_ttl = false;

    if (!is_int($ttl)) {
        $errstr = __METHOD__.' Expects an integer argument.';
        throw new \InvalidArgumentException($errstr);
    }

    # First run.
    if (false === $_ttl) {
        header('Expires: '.gmdate("D, d M Y H:i:s \G\M\T", time() + $ttl));
        header('Cache-Control: max-age='.$ttl.', public');
        header("Pragma: Public");
        $_ttl = 0;
    }
    $_ttl = $ttl;

    # Determine if the client has sent a header that is a "hit"
    # and a 304 response should be sent.
    $is_hit = function($key, $value) {
        if (empty($_SERVER[$key]) || false === $value) {
            return false;
        }
        $client = trim(strtolower($_SERVER[$key]), " \t\n\r\0\x0B\"',; ");
        $client = array_filter(array_map(function($value) {
            return trim($value, " \t\n\r\0\x0B\"',; ");
        }, preg_split('/\s*[,;]\s*/', $client, PREG_SPLIT_NO_EMPTY)));
        if (empty($client)) {
            $client = ['MISS'];
        }
        return in_array($value, $client) || in_array('*', $client);
    };

    # Oh yeah babe, this is a real closure.
    return function($content, $bits) use ($_ttl, $is_hit) {
        static $_buffer = '';
        # Only run when the output buffer has completed.
        if(!($bits & PHP_OUTPUT_HANDLER_END)) {
            $_buffer .= $content;
            return '';
        }
        $_local = $_buffer.$content;
        $_buffer = '';
        # or in_array($code, [200, 203, 300, 301, 302, 404, 410]) - cacheable?
        if(!headers_sent() && !in_array(http_response_code(), [201, 204, 304])) {
            $cont_md5 = md5($local);
            $last_mod = gmdate("D, d M Y H:i:s \G\M\T", time() - $_ttl);
            header("Content-MD5: $cont_md5"); # ?? @todo
            header("Content-Length: ".mb_strlen($local));
            # header('Cache-Control: public');
            if ($is_hit('HTTP_IF_NONE_MATCH', $cont_md5)
            || $is_hit('HTTP_IF_MODIFIED_SINCE', $last_mod)) {
                http_response_code(304);
                $_local = '';
            } else {
                # $last_modified = date(\DateTime::RFC2822, $last_modified);
                header("ETag: \"$cont_md5\"");
                header("Last-Modified: $last_mod");
            }
        }
        return $_local;
    };
}

