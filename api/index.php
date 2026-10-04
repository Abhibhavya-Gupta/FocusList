<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Env.php';
require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Jwt.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/HeuristicAi.php';
require_once __DIR__ . '/src/AiService.php';
require_once __DIR__ . '/src/AuthController.php';
require_once __DIR__ . '/src/TodoController.php';
require_once __DIR__ . '/src/AiController.php';

Env::load(dirname(__DIR__) . '/.env');
Response::cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*?/api#', '', $path) ?? '';
$path = '/' . trim($path, '/');
if ($path === '//') {
    $path = '/';
}

$segments = array_values(array_filter(explode('/', trim($path, '/'))));

function jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Request body must be valid JSON', 400);
    }
    return $data;
}

try {
    Database::migrate();

    if ($method === 'GET' && ($segments === [] || $segments[0] === 'health')) {
        Response::json(['ok' => true, 'app' => 'dayline']);
    }

    if (($segments[0] ?? '') === 'register' && $method === 'POST') {
        AuthController::register(jsonBody());
    }
    if (($segments[0] ?? '') === 'login' && $method === 'POST') {
        AuthController::login(jsonBody());
    }
    if (($segments[0] ?? '') === 'me' && $method === 'GET') {
        AuthController::me();
    }

    if (($segments[0] ?? '') === 'todos') {
        $user = Auth::requireUser();
        if ($method === 'GET' && count($segments) === 1) {
            TodoController::index($user);
        }
        if ($method === 'POST' && count($segments) === 1) {
            TodoController::create($user, jsonBody());
        }
        if (isset($segments[1]) && ctype_digit($segments[1])) {
            $id = (int) $segments[1];
            if ($method === 'PUT' || $method === 'PATCH') {
                if (($segments[2] ?? '') === 'status') {
                    TodoController::updateStatus($user, $id, jsonBody());
                }
                TodoController::update($user, $id, jsonBody());
            }
            if ($method === 'DELETE') {
                TodoController::delete($user, $id);
            }
        }
    }

    if (($segments[0] ?? '') === 'ai') {
        $user = Auth::requireUser();
        $action = $segments[1] ?? '';
        if ($method === 'POST' && $action === 'parse') {
            AiController::parse($user, jsonBody());
        }
        if ($method === 'POST' && $action === 'breakdown') {
            AiController::breakdown($user, jsonBody());
        }
        if ($method === 'POST' && $action === 'plan') {
            AiController::plan($user, jsonBody());
        }
        if ($method === 'POST' && $action === 'suggest') {
            AiController::suggest($user);
        }
    }

    Response::error('Not found', 404);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code >= 400 && $code < 500) {
        Response::error($e->getMessage() ?: 'Request failed', $code);
    }
    error_log((string) $e);
    Response::error('An unexpected server error occurred', 500);
}
