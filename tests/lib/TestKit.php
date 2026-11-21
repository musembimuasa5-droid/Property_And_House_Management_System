<?php
/**
 * Minimal test kit for the command-line runner: case registry, assertions and an HTTP
 * client that keeps a session cookie and reads CSRF tokens like a browser would.
 */

final class TestFailure extends RuntimeException {}

final class TestKit {
    /** @var array<string, array> */
    public static array $cases = [];

    public static function add(string $id, string $title, string $preconditions, array $steps, string $expected, callable $run): void {
        self::$cases[$id] = ['id' => $id, 'suite' => strtok($id, '-'), 'title' => $title, 'preconditions' => $preconditions,
            'steps' => $steps, 'expected' => $expected, 'run' => $run];
    }
}

function check(bool $condition, string $whatFailed): void {
    if (!$condition) throw new TestFailure($whatFailed);
}

/** Run $fn inside a transaction that is always rolled back, so integration tests leave no trace. */
function in_rollback(PDO $db, callable $fn) {
    $db->beginTransaction();
    try {
        return $fn($db);
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

final class Browser {
    private string $jar;
    public array $last = ['status' => 0, 'body' => '', 'location' => null, 'headers' => '', 'seconds' => 0.0];

    public function __construct(private string $base) {
        $this->jar = tempnam(sys_get_temp_dir(), 'ue-cookies-');
    }

    public function __destruct() {
        if (is_file($this->jar)) unlink($this->jar);
    }

    /** Flatten nested form fields (item_condition[0]) for multipart requests. */
    private static function flatten(array $fields, string $prefix = ''): array {
        $flat = [];
        foreach ($fields as $key => $value) {
            $name = $prefix === '' ? (string)$key : "{$prefix}[{$key}]";
            if (is_array($value)) $flat += self::flatten($value, $name);
            else $flat[$name] = $value;
        }
        return $flat;
    }

    public function request(string $method, string $path, array $fields = [], array $files = []): array {
        $url = preg_match('#^https?://#', $path) ? $path : $this->base . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($files) {
                $payload = self::flatten($fields);
                foreach ($files as $name => $file) $payload[$name] = new CURLFile($file['path'], $file['mime'], $file['name']);
            } else {
                $payload = http_build_query($fields);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $started = microtime(true);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $location);
        $this->last = ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, $headerSize),
            'location' => $location[1] ?? null, 'headers' => $headers, 'seconds' => microtime(true) - $started];
        curl_close($ch);
        return $this->last;
    }

    public function get(string $path): array {
        return $this->request('GET', $path);
    }

    /** GET a page and follow up to three redirects. */
    public function open(string $path): array {
        $response = $this->get($path);
        for ($i = 0; $i < 3 && $response['location']; $i++) $response = $this->get($response['location']);
        return $response;
    }

    public function token(string $path = '/account/settings.php'): string {
        $page = $this->open($path);
        if (!preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $match)) throw new TestFailure("No CSRF token found on $path");
        return $match[1];
    }

    /** POST with a fresh CSRF token; returns the first response and, after a redirect, the next page as 'after'. */
    public function submit(string $path, array $fields, array $files = [], ?string $tokenPage = null): array {
        $fields['csrf_token'] ??= $this->token($tokenPage ?? $path);
        $response = $this->request('POST', $path, $fields, $files);
        $response['after'] = $response['location'] ? $this->open($response['location'])['body'] : $response['body'];
        return $response;
    }

    public function login(string $email, string $password): array {
        $token = $this->token('/public/login.php');
        return $this->request('POST', '/public/login.php', ['csrf_token' => $token, 'email' => $email, 'password' => $password]);
    }

    public function cookie(string $name): ?string {
        foreach (file($this->jar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === $name) return $parts[6];
        }
        return null;
    }
}

function path_of(?string $url): string {
    return $url === null ? '' : (string)preg_replace('#^https?://[^/]+#', '', $url);
}

function text_of(string $html): string {
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}
