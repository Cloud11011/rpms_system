<?php
/** Isolated guard regression checks. No configuration, database, or sessions are opened. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') === '--case') {
    $fixture = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $_SESSION = ['user_id' => 1, 'must_change_password' => $fixture['sessionFlag']];
    if (empty($fixture['legacySession'])) {
        $_SESSION['credential_fingerprint'] = hash('sha256', $fixture['sessionHash'] ?? 'fixture-hash');
    }
    $_SERVER['SCRIPT_NAME'] = $fixture['script'] ?? 'ierb_api.php';
    $_GET = ['action' => $fixture['action'] ?? 'list'];
    $fixtureUser = ['id' => 1, 'status' => $fixture['status'] ?? 'Active',
        'role' => $fixture['role'] ?? 'admin', 'must_change_password' => $fixture['databaseFlag'],
        'password_hash' => $fixture['databaseHash'] ?? 'fixture-hash'];

    function db() {
        return new class {
            public function prepare($sql) {
                return new class {
                    public function execute($params): void {}
                    public function fetch() { return $GLOBALS['fixtureUser']; }
                };
            }
        };
    }

    // Extract only the real guards. Never include config.php or execute its bootstrap.
    $tokens = token_get_all(file_get_contents(__DIR__ . '/../config.php'));
    $wanted = ['current_user', 'require_login', 'api_require_login'];
    $functions = [];
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        $name = is_array($tokens[$j]) ? $tokens[$j][1] : '';
        if (!in_array($name, $wanted, true)) continue;
        $source = '';
        $depth = 0;
        $body = false;
        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $source .= is_array($token) ? $token[1] : $token;
            if ($token === '{') { $depth++; $body = true; }
            if ($token === '}' && --$depth === 0 && $body) break;
        }
        $functions[$name] = $source;
    }
    if (count($functions) !== count($wanted)) throw new RuntimeException('Guard extraction failed.');
    eval(implode("\n", $functions));
    if (!empty($fixture['html'])) {
        require_login(['admin']);
    } else {
        api_require_login(['admin']);
    }
    echo json_encode(['allowed' => true, 'required' => $_SESSION['must_change_password']]);
    exit;
}

$cases = [
    ['name' => 'Password recovery invalidates older sessions', 'databaseFlag' => 0, 'sessionFlag' => 0, 'databaseHash' => 'changed-hash', 'expect' => 'You must be logged in.'],
    ['name' => 'Pre-binding sessions require a fresh login', 'databaseFlag' => 0, 'sessionFlag' => 0, 'legacySession' => true, 'expect' => 'You must be logged in.'],
    ['name' => 'Refreshed credential binding permits the current session', 'databaseFlag' => 0, 'sessionFlag' => 0, 'databaseHash' => 'changed-hash', 'sessionHash' => 'changed-hash', 'expect' => 'allowed'],
    ['name' => 'Reset account blocks an existing API session', 'databaseFlag' => 1, 'sessionFlag' => 0, 'expect' => 'password_change_required'],
    ['name' => 'Completed change clears a stale session flag', 'databaseFlag' => 0, 'sessionFlag' => 1, 'expect' => 'allowed'],
    ['name' => 'Required change permits profile read', 'databaseFlag' => 1, 'sessionFlag' => 0, 'script' => 'profile_api.php', 'action' => 'me', 'expect' => 'allowed'],
    ['name' => 'Required change permits password change', 'databaseFlag' => 1, 'sessionFlag' => 0, 'script' => 'profile_api.php', 'action' => 'change_password', 'expect' => 'allowed'],
    ['name' => 'Required change blocks profile updates', 'databaseFlag' => 1, 'sessionFlag' => 0, 'script' => 'profile_api.php', 'action' => 'update_profile', 'expect' => 'password_change_required'],
    ['name' => 'Role restrictions remain enforced', 'databaseFlag' => 0, 'sessionFlag' => 0, 'role' => 'adviser', 'expect' => 'You are not authorized to perform this action.'],
    ['name' => 'Inactive accounts remain blocked', 'databaseFlag' => 0, 'sessionFlag' => 0, 'status' => 'Inactive', 'expect' => 'You must be logged in.'],
    ['name' => 'Reset account blocks an existing page session', 'databaseFlag' => 1, 'sessionFlag' => 0, 'html' => true, 'expect' => 'redirect'],
    ['name' => 'Password change page stays accessible', 'databaseFlag' => 1, 'sessionFlag' => 0, 'html' => true, 'script' => 'change_password_required.php', 'expect' => 'allowed'],
];
foreach ($cases as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated PHP case.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $data = json_decode($output, true);
    $passed = $case['expect'] === 'redirect' ? $output === '' :
        ($case['expect'] === 'allowed' ? !empty($data['allowed']) && $data['required'] === $case['databaseFlag'] :
            ($data['code'] ?? $data['message'] ?? '') === $case['expect']);
    if ($exit !== 0 || $error !== '' || !$passed) {
        fwrite(STDERR, 'FAIL: ' . $case['name'] . "\n" . $error . $output . "\n");
        exit(1);
    }
    echo 'PASS: ' . $case['name'] . "\n";
}
