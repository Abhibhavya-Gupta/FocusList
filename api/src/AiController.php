<?php
declare(strict_types=1);

final class AiController
{
    public static function parse(array $user, array $body): never
    {
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '') {
            Response::error('Type what you need to do');
        }
        if (strlen($text) > 2000) {
            Response::error('Task description must be 2,000 characters or fewer');
        }
        $today = $body['today'] ?? null;
        if ($today !== null && (!is_string($today) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $today))) {
            Response::error('Today must be a valid YYYY-MM-DD date');
        }
        if ($today !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
            if ($date === false || $date->format('Y-m-d') !== $today) {
                Response::error('Today must be a valid YYYY-MM-DD date');
            }
        }
        Response::json(['task' => AiService::parse($text, $today), 'user' => $user['name']]);
    }

    public static function breakdown(array $user, array $body): never
    {
        $title = trim((string) ($body['title'] ?? ''));
        $todoId = isset($body['todo_id']) ? (int) $body['todo_id'] : 0;
        if ($todoId) {
            $todo = TodoController::find((int) $user['id'], $todoId);
            $title = $todo['title'];
        }
        if ($title === '') {
            Response::error('Pick a task to break down');
        }
        if (strlen($title) > 255) {
            Response::error('Task title must be 255 characters or fewer');
        }
        $result = AiService::breakdown($title);
        $created = [];
        if ($todoId && !empty($result['steps'])) {
            $pdo = Database::pdo();
            $ins = $pdo->prepare(
                'INSERT INTO todos (user_id, title, notes, category, priority, due_date, status, parent_id)
                 VALUES (?, ?, ?, ?, ?, ?, "open", ?)'
            );
            foreach ($result['steps'] as $step) {
                $ins->execute([
                    (int) $user['id'],
                    $step,
                    '',
                    $todo['category'] ?? 'General',
                    $todo['priority'] ?? 'medium',
                    $todo['due_date'] ?? null,
                    $todoId,
                ]);
                $created[] = TodoController::find((int) $user['id'], (int) $pdo->lastInsertId());
            }
        }
        Response::json(['breakdown' => $result, 'todos' => $created]);
    }

    public static function plan(array $user, array $body): never
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM todos WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        Response::json(['plan' => AiService::plan($stmt->fetchAll(), $user['name'])]);
    }

    public static function suggest(array $user): never
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM todos WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        Response::json(AiService::suggest($stmt->fetchAll()));
    }
}
