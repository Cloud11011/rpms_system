<?php
/**
 * Atomic authentication-attempt counters; STORAGE_DIR is required when called.
 * Fixed windows expire logically. Counter files stay on disk to avoid deleting an
 * inode while another request waits for its lock. Reclaim old counters only during
 * maintenance with authentication traffic stopped, using a bounded directory iterator.
 */
function consume_auth_attempt(string $scope, string $subject, int $limit, int $windowSeconds): bool
{
    if ($scope === '' || $limit < 1 || $windowSeconds < 1 || !defined('STORAGE_DIR')) return auth_rate_limit_unavailable();
    $handle = $mutex = false;
    try {
        $directory = rtrim((string)STORAGE_DIR, '/\\') . DIRECTORY_SEPARATOR . 'auth_rate_limits';
        if (is_link($directory)) return auth_rate_limit_unavailable();
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) return auth_rate_limit_unavailable();
        if (!@chmod($directory, 0700)) return auth_rate_limit_unavailable();
        $mutexPath = $directory . DIRECTORY_SEPARATOR . '.lock';
        if (is_link($mutexPath)) return auth_rate_limit_unavailable();
        $mutex = @fopen($mutexPath, 'c+b');
        if (!is_resource($mutex) || !@chmod($mutexPath, 0600) || !@flock($mutex, LOCK_EX)) return auth_rate_limit_unavailable();
        // Include first-file initialization under the mutex so another request cannot see an empty counter.
        if (!auth_rate_limit_protect_directory($directory)) return auth_rate_limit_unavailable();
        $key = hash('sha256', strlen($scope) . ':' . $scope . $subject);
        $path = $directory . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_link($path)) return auth_rate_limit_unavailable();
        $handle = @fopen($path, 'x+b');
        $created = is_resource($handle);
        if (!$created) {
            if (!is_file($path)) return auth_rate_limit_unavailable();
            $handle = @fopen($path, 'r+b');
        }
        if (!is_resource($handle) || !@chmod($path, 0600) || !@flock($handle, LOCK_EX)) return auth_rate_limit_unavailable();
        $stats = @fstat($handle);
        if (!$stats || $stats['size'] > 256) return auth_rate_limit_unavailable();
        $now = time();
        if ($created && $stats['size'] === 0) {
            $state = ['startedAt' => $now, 'attempts' => 0];
        } else {
            $raw = @stream_get_contents($handle, 257);
            $state = is_string($raw) ? json_decode($raw, true, 4, JSON_THROW_ON_ERROR) : null;
            if (!is_array($state) || count($state) !== 2 || !isset($state['startedAt'], $state['attempts'])
                || !is_int($state['startedAt']) || $state['startedAt'] < 0 || $state['startedAt'] > $now
                || !is_int($state['attempts']) || $state['attempts'] < 0) return auth_rate_limit_unavailable();
            if ($now - $state['startedAt'] >= $windowSeconds) $state = ['startedAt' => $now, 'attempts' => 0];
        }
        if ($state['attempts'] >= $limit) return false;
        $state['attempts']++;
        $encoded = json_encode($state, JSON_THROW_ON_ERROR) . "\n";
        if (!@rewind($handle) || !@ftruncate($handle, 0)
            || @fwrite($handle, $encoded) !== strlen($encoded) || !@fflush($handle)) return auth_rate_limit_unavailable();
        return true;
    } catch (Throwable $error) {
        return auth_rate_limit_unavailable();
    } finally {
        foreach ([$handle, $mutex] as $resource) {
            if (is_resource($resource)) { @flock($resource, LOCK_UN); @fclose($resource); }
        }
    }
}

function auth_rate_limit_protect_directory(string $directory): bool
{
    $path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_link($path)) return false;
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) return false;
    try {
        if (!@chmod($path, 0600) || !@flock($handle, LOCK_EX)) return false;
        $expected = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
        $stats = @fstat($handle);
        if (!$stats || $stats['size'] > 1024) return false;
        if ($stats['size'] === 0) return @fwrite($handle, $expected) === strlen($expected) && @fflush($handle);
        return @stream_get_contents($handle, 1025) === $expected;
    } finally { @flock($handle, LOCK_UN); @fclose($handle); }
}

function auth_rate_limit_unavailable(): bool
{
    $message = 'Authentication attempt limiting is unavailable; the request was blocked.';
    try {
        if (function_exists('log_api_error')) log_api_error('auth_rate_limit', $message);
        else @error_log($message);
    } catch (Throwable $error) { @error_log($message); }
    return false;
}
