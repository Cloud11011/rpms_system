<?php
/** Version application-relative CSS/JS URLs without loading configuration or services. */
function asset_url(string $path): string
{
    // Only inspect local assets; leave external URLs and unsupported paths untouched.
    $asset = preg_split('/[?#]/', $path, 2)[0];
    if (!preg_match('~^assets/(?:css|js)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:css|js)$~D', $asset)) {
        return $path;
    }
    $file = dirname(__DIR__) . '/' . $asset;
    $mtime = @is_file($file) ? @filemtime($file) : false;
    if ($mtime === false) return $path;

    // Keep any existing query parameters and put the version before the fragment.
    $fragment = strpos($path, '#');
    $url = $fragment === false ? $path : substr($path, 0, $fragment);
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $mtime
        . ($fragment === false ? '' : substr($path, $fragment));
}
