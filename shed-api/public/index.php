<?php
declare(strict_types=1);

// ============================================
// SHED API RESPONSE HEADERS
// ============================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');

// ============================================
// DEVELOPMENT LAN VALIDATION
// ============================================

function isPrivateLanIp(string $ip): bool
{
    if (
        filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4
        ) === false
    ) {
        return false;
    }

    $parts = array_map(
        'intval',
        explode('.', $ip)
    );

    if ($parts[0] === 10) {
        return true;
    }

    if (
        $parts[0] === 172 &&
        $parts[1] >= 16 &&
        $parts[1] <= 31
    ) {
        return true;
    }

    return (
        $parts[0] === 192 &&
        $parts[1] === 168
    );
}

// ============================================
// NETWORK ACCESS CHECK
// ============================================

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

$isLocalClient = in_array(
    $clientIp,
    ['127.0.0.1', '::1'],
    true
);

$isPrivateClient = isPrivateLanIp(
    $clientIp
);

if (!$isLocalClient && !$isPrivateClient) {
    respond(403, [
        'success' => false,
        'message' => 'Local network access only.'
    ]);
}

// ============================================
// DEVELOPMENT CORS
// ============================================

$allowedFrontendPorts = [
    8100,
    8101
];

if ($origin !== '') {

    $originParts = parse_url($origin);

    if (!is_array($originParts)) {
        respond(403, [
            'success' => false,
            'message' => 'Invalid request origin.'
        ]);
    }

    $originScheme =
        $originParts['scheme'] ?? '';

    $originHost =
        $originParts['host'] ?? '';

    $originPort =
        $originParts['port'] ?? null;

    $isLocalOrigin = in_array(
        $originHost,
        ['localhost', '127.0.0.1'],
        true
    );

    $isPrivateOrigin = isPrivateLanIp(
        $originHost
    );

    // Ionic browser:
    // http://localhost:8101
    // http://192.168.x.x:8101
    $isBrowserOrigin = (
        $originScheme === 'http' &&
        (
            $isLocalOrigin ||
            $isPrivateOrigin
        ) &&
        in_array(
            $originPort,
            $allowedFrontendPorts,
            true
        )
    );

    // Capacitor Android WebView:
    // http://localhost
    // Also accept the modern HTTPS-origin variant,
    // although this APK config selects HTTP.
    $isAndroidAppOrigin = (
        in_array(
            $originScheme,
            ['http', 'https'],
            true
        ) &&
        $originHost === 'localhost' &&
        $originPort === null
    );

    // Capacitor iOS WebView origin.
    $isIosAppOrigin = (
        $originScheme === 'capacitor' &&
        $originHost === 'localhost' &&
        $originPort === null
    );

    $hasUnexpectedOriginComponents = (
        isset($originParts['user']) ||
        isset($originParts['pass']) ||
        isset($originParts['path']) ||
        isset($originParts['query']) ||
        isset($originParts['fragment'])
    );

    $isAllowedOrigin = (
        (
            $isBrowserOrigin ||
            $isAndroidAppOrigin ||
            $isIosAppOrigin
        ) &&
        !$hasUnexpectedOriginComponents
    );

    if (!$isAllowedOrigin) {
        respond(403, [
            'success' => false,
            'message' => 'Origin not allowed.'
        ]);
    }

    header(
        'Access-Control-Allow-Origin: ' . $origin
    );

    header(
        'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS'
    );

    header(
        'Access-Control-Allow-Headers: Authorization, Content-Type'
    );

    header('Access-Control-Max-Age: 600');
}

// ============================================
// CORS PREFLIGHT
// ============================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') ===
    'OPTIONS'
) {

    if ($origin === '') {
        respond(403, [
            'success' => false,
            'message' => 'Origin required.'
        ]);
    }

    http_response_code(204);
    exit;
}

// ============================================
// LOAD APPLICATION FILES
// ============================================

require_once dirname(__DIR__) .
    '/config/database.php';

require_once dirname(__DIR__) .
    '/src/helpers/Validation.php';

require_once dirname(__DIR__) .
    '/src/controllers/PeriodController.php';

require_once dirname(__DIR__) .
    '/src/controllers/WellnessController.php';

// ============================================
// JSON RESPONSE
// ============================================

function respond(int $status, array $data): never
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// ============================================
// READ JSON REQUEST
// ============================================

function readJson(): array
{
    $contentType =
        $_SERVER['CONTENT_TYPE'] ?? '';

    if (
        !str_starts_with(
            strtolower($contentType),
            'application/json'
        )
    ) {
        respond(415, [
            'success' => false,
            'message' =>
                'Content-Type must be application/json.'
        ]);
    }

    $body = file_get_contents(
        'php://input',
        false,
        null,
        0,
        8193
    );

    if (
        $body === false ||
        strlen($body) > 8192
    ) {
        respond(413, [
            'success' => false,
            'message' => 'Request body is too large.'
        ]);
    }

    try {
        $data = json_decode(
            $body,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body.'
        ]);
    }

    if (
        !is_array($data) ||
        array_is_list($data)
    ) {
        respond(400, [
            'success' => false,
            'message' => 'Expected a JSON object.'
        ]);
    }

    return $data;
}

// ============================================
// BEARER TOKEN
// ============================================

function getBearerToken(): ?string
{
    $header =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (
        $header === '' &&
        function_exists('getallheaders')
    ) {
        foreach (
            getallheaders() as $name => $value
        ) {
            if (
                strcasecmp(
                    $name,
                    'Authorization'
                ) === 0
            ) {
                $header = $value;
                break;
            }
        }
    }

    if (
        preg_match(
            '/^Bearer ([a-f0-9]{64})$/i',
            $header,
            $matches
        )
    ) {
        return strtolower($matches[1]);
    }

    return null;
}

// ============================================
// REQUIRE AUTHENTICATED USER
// ============================================

function requireUser(PDO $pdo): array
{
    $token = getBearerToken();

    if ($token === null) {
        respond(401, [
            'success' => false,
            'message' => 'Authentication required.'
        ]);
    }

    $statement = $pdo->prepare(
        'SELECT users.id,
                users.display_name,
                users.email
         FROM api_tokens
         INNER JOIN users
            ON users.id = api_tokens.user_id
         WHERE api_tokens.token_hash = ?
           AND api_tokens.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );

    $statement->execute([
        hash('sha256', $token)
    ]);

    $user = $statement->fetch();

    if (!$user) {
        respond(401, [
            'success' => false,
            'message' => 'Invalid or expired token.'
        ]);
    }

    return $user;
}

// ============================================
// REGISTER ACCOUNT
// ============================================

function registerUser(PDO $pdo): never
{
    $data = readJson();

    $name = $data['displayName'] ?? null;
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;

    if (
        !is_string($name) ||
        !is_string($email) ||
        !is_string($password)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid registration fields.'
        ]);
    }

    $name = trim($name);
    $email = strtolower(trim($email));

    if (
        mb_strlen($name) < 2 ||
        mb_strlen($name) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 191 ||
        strlen($password) < 12 ||
        strlen($password) > 128
    ) {
        respond(422, [
            'success' => false,
            'message' =>
                'Use a valid name, email, and a password of 12–128 characters.'
        ]);
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    try {
        $statement = $pdo->prepare(
            'INSERT INTO users
             (display_name, email, password_hash)
             VALUES (?, ?, ?)'
        );

        $statement->execute([
            $name,
            $email,
            $passwordHash
        ]);

    } catch (PDOException $exception) {

        if ($exception->getCode() === '23000') {
            respond(409, [
                'success' => false,
                'message' => 'Email is already registered.'
            ]);
        }

        throw $exception;
    }

    respond(201, [
        'success' => true,
        'message' => 'Account registered successfully.'
    ]);
}

// ============================================
// LOGIN
// ============================================

function loginUser(PDO $pdo): never
{
    $data = readJson();

    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;

    if (
        !is_string($email) ||
        !is_string($password)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Email and password are required.'
        ]);
    }

    $statement = $pdo->prepare(
        'SELECT id, display_name, email, password_hash
         FROM users
         WHERE email = ?
         LIMIT 1'
    );

    $statement->execute([
        strtolower(trim($email))
    ]);

    $user = $statement->fetch();

    if (
        !$user ||
        !password_verify(
            $password,
            $user['password_hash']
        )
    ) {
        respond(401, [
            'success' => false,
            'message' => 'Invalid email or password.'
        ]);
    }

    $token = bin2hex(
        random_bytes(32)
    );

    $statement = $pdo->prepare(
        'INSERT INTO api_tokens
         (user_id, token_hash, expires_at)
         VALUES (
             ?,
             ?,
             DATE_ADD(
                 UTC_TIMESTAMP(),
                 INTERVAL 24 HOUR
             )
         )'
    );

    $statement->execute([
        $user['id'],
        hash('sha256', $token)
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Login successful.',
        'tokenType' => 'Bearer',
        'accessToken' => $token,
        'expiresIn' => 86400,
        'user' => [
            'id' => (int) $user['id'],
            'displayName' => $user['display_name'],
            'email' => $user['email']
        ]
    ]);
}

// ============================================
// CHANGE EMAIL
// ============================================

function changeUserEmail(PDO $pdo): never
{
    $user = requireUser($pdo);
    $data = readJson();

    $newEmail = $data['newEmail'] ?? null;
    $currentPassword =
        $data['currentPassword'] ?? null;

    if (
        !is_string($newEmail) ||
        !is_string($currentPassword)
    ) {
        respond(422, [
            'success' => false,
            'message' =>
                'New email and current password are required.'
        ]);
    }

    $newEmail = strtolower(trim($newEmail));

    if (
        !filter_var(
            $newEmail,
            FILTER_VALIDATE_EMAIL
        ) ||
        strlen($newEmail) > 191
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid email address.'
        ]);
    }

    if (
        $newEmail === strtolower(
            (string) $user['email']
        )
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Enter a different email address.'
        ]);
    }

    $statement = $pdo->prepare(
        'SELECT password_hash
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $statement->execute([
        (int) $user['id']
    ]);

    $account = $statement->fetch();

    if (
        !$account ||
        !password_verify(
            $currentPassword,
            $account['password_hash']
        )
    ) {
        respond(401, [
            'success' => false,
            'message' => 'Incorrect current password.'
        ]);
    }

    try {
        $statement = $pdo->prepare(
            'UPDATE users
             SET email = ?
             WHERE id = ?'
        );

        $statement->execute([
            $newEmail,
            (int) $user['id']
        ]);

    } catch (PDOException $exception) {

        if ($exception->getCode() === '23000') {
            respond(409, [
                'success' => false,
                'message' =>
                    'Email address is already in use.'
            ]);
        }

        throw $exception;
    }

    respond(200, [
        'success' => true,
        'message' => 'Email updated successfully.'
    ]);
}

// ============================================
// CHANGE PASSWORD
// ============================================

function changeUserPassword(PDO $pdo): never
{
    $user = requireUser($pdo);
    $data = readJson();

    $currentPassword =
        $data['currentPassword'] ?? null;

    $newPassword =
        $data['newPassword'] ?? null;

    if (
        !is_string($currentPassword) ||
        !is_string($newPassword)
    ) {
        respond(422, [
            'success' => false,
            'message' =>
                'Current and new passwords are required.'
        ]);
    }

    if (
        strlen($newPassword) < 12 ||
        strlen($newPassword) > 128
    ) {
        respond(422, [
            'success' => false,
            'message' =>
                'New password must contain 12–128 characters.'
        ]);
    }

    $statement = $pdo->prepare(
        'SELECT password_hash
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $statement->execute([
        (int) $user['id']
    ]);

    $account = $statement->fetch();

    if (
        !$account ||
        !password_verify(
            $currentPassword,
            $account['password_hash']
        )
    ) {
        respond(401, [
            'success' => false,
            'message' => 'Incorrect current password.'
        ]);
    }

    if (
        password_verify(
            $newPassword,
            $account['password_hash']
        )
    ) {
        respond(422, [
            'success' => false,
            'message' =>
                'Choose a different new password.'
        ]);
    }

    $newHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'UPDATE users
             SET password_hash = ?
             WHERE id = ?'
        );

        $statement->execute([
            $newHash,
            (int) $user['id']
        ]);

        // Revoke other sessions while preserving
        // the current authenticated session.
        $tokenHash = hash(
            'sha256',
            (string) getBearerToken()
        );

        $statement = $pdo->prepare(
            'DELETE FROM api_tokens
             WHERE user_id = ?
               AND token_hash <> ?'
        );

        $statement->execute([
            (int) $user['id'],
            $tokenHash
        ]);

        $pdo->commit();

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    respond(200, [
        'success' => true,
        'message' => 'Password updated successfully.'
    ]);
}

// ============================================
// LOGOUT
// ============================================

function logoutUser(PDO $pdo): never
{
    requireUser($pdo);

    $token = getBearerToken();

    $statement = $pdo->prepare(
        'DELETE FROM api_tokens
         WHERE token_hash = ?'
    );

    $statement->execute([
        hash('sha256', (string) $token)
    ]);

    respond(200, [
        'success' => true,
        'message' => 'Logged out successfully.'
    ]);
}

// ============================================
// ROUTING
// ============================================

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

$path = rtrim(
    (string) $path,
    '/'
);

$method =
    $_SERVER['REQUEST_METHOD'] ?? 'GET';

$basePath = '/shed-api/public/api';

$routes = [
    "$basePath/health" => ['GET'],
    "$basePath/auth/register" => ['POST'],
    "$basePath/auth/login" => ['POST'],
    "$basePath/auth/me" => ['GET'],
    "$basePath/auth/logout" => ['POST'],
    "$basePath/auth/change-email" => ['POST'],
    "$basePath/auth/change-password" => ['POST']
];

// ============================================
// CRUD ROUTE MATCHING
// ============================================

$crudMatch = [];

$isCrudRoute = preg_match(
    '~^/shed-api/public/api/(periods|wellness)(?:/([1-9][0-9]*))?$~D',
    $path,
    $crudMatch
) === 1;

if (
    !$isCrudRoute &&
    !isset($routes[$path])
) {
    respond(404, [
        'success' => false,
        'message' => 'Endpoint not found.'
    ]);
}

if (
    !$isCrudRoute &&
    !in_array(
        $method,
        $routes[$path],
        true
    )
) {
    header(
        'Allow: ' .
        implode(', ', $routes[$path])
    );

    respond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// ============================================
// DATABASE + ENDPOINT EXECUTION
// ============================================

try {
    $pdo = getDatabaseConnection();

    if ($isCrudRoute) {

        $user = requireUser($pdo);
        $userId = (int) $user['id'];

        $recordId = null;

        if (
            isset($crudMatch[2]) &&
            $crudMatch[2] !== ''
        ) {
            $recordId = filter_var(
                $crudMatch[2],
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1
                    ]
                ]
            );

            if ($recordId === false) {
                respond(404, [
                    'success' => false,
                    'message' => 'Invalid record ID.'
                ]);
            }
        }

        if ($crudMatch[1] === 'periods') {
            handlePeriodRequest(
                $pdo,
                $method,
                $recordId,
                $userId
            );
        }

        if ($crudMatch[1] === 'wellness') {
            handleWellnessRequest(
                $pdo,
                $method,
                $recordId,
                $userId
            );
        }
    }

    switch ($path) {

        case "$basePath/health":
            $pdo->query('SELECT 1');

            respond(200, [
                'success' => true,
                'application' => 'Shed API',
                'database' => 'connected',
                'message' => 'Shed API is running.'
            ]);

        case "$basePath/auth/register":
            registerUser($pdo);

        case "$basePath/auth/login":
            loginUser($pdo);

        case "$basePath/auth/me":
            $user = requireUser($pdo);

            respond(200, [
                'success' => true,
                'user' => [
                    'id' => (int) $user['id'],
                    'displayName' =>
                        $user['display_name'],
                    'email' => $user['email']
                ]
            ]);

        case "$basePath/auth/change-email":
            changeUserEmail($pdo);

        case "$basePath/auth/change-password":
            changeUserPassword($pdo);

        case "$basePath/auth/logout":
            logoutUser($pdo);
    }

    respond(404, [
        'success' => false,
        'message' => 'Endpoint not found.'
    ]);

} catch (Throwable $exception) {

    error_log(
        'Shed API error: ' .
        $exception->getMessage()
    );

    respond(500, [
        'success' => false,
        'message' =>
            'An internal server error occurred.'
    ]);
}