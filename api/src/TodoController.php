<?php
declare(strict_types=1);

final class TodoController
{
    public static function index(array $user): never
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM todos WHERE user_id = ? ORDER BY
              (status = "done"),
              FIELD(priority, "high", "medium", "low"),
              ISNULL(due_date), due_date, due_time, id DESC'
        );
        $stmt->execute([(int) $user['id']]);
        Response::json(['todos' => $stmt->fetchAll()]);
    }

    public static function create(array $user, array $body): never
    {
        $todo = self::sanitize($body, true);
        if ($todo['parent_id'] !== null) {
            self::find((int) $user['id'], $todo['parent_id']);
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO todos (user_id, title, notes, category, priority, due_date, due_time, status, parent_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $user['id'],
            $todo['title'],
            $todo['notes'],
            $todo['category'],
            $todo['priority'],
            $todo['due_date'],
            $todo['due_time'],
            $todo['status'],
            $todo['parent_id'],
        ]);
        Response::json(['todo' => self::find((int) $user['id'], (int) Database::pdo()->lastInsertId())], 201);
    }

    public static function update(array $user, int $id, array $body): never
    {
        self::owned((int) $user['id'], $id);
        $todo = self::sanitize($body, false);
        $stmt = Database::pdo()->prepare(
            'UPDATE todos SET title = COALESCE(?, title), notes = COALESCE(?, notes), category = COALESCE(?, category),
             priority = COALESCE(?, priority), due_date = IF(?, ?, due_date), due_time = IF(?, ?, due_time),
             status = COALESCE(?, status)
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $todo['title'],
            $todo['notes'],
            $todo['category'],
            $todo['priority'],
            $todo['due_date_set'],
            $todo['due_date'],
            $todo['due_time_set'],
            $todo['due_time'],
            $todo['status'],
            $id,
            (int) $user['id'],
        ]);
        Response::json(['todo' => self::find((int) $user['id'], $id)]);
    }

    public static function updateStatus(array $user, int $id, array $body): never
    {
        self::owned((int) $user['id'], $id);
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['open', 'doing', 'done'], true)) {
            Response::error('Invalid status');
        }
        $stmt = Database::pdo()->prepare('UPDATE todos SET status = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$status, $id, (int) $user['id']]);
        Response::json(['todo' => self::find((int) $user['id'], $id)]);
    }

    public static function delete(array $user, int $id): never
    {
        self::owned((int) $user['id'], $id);
        $stmt = Database::pdo()->prepare('DELETE FROM todos WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, (int) $user['id']]);
        Response::json(['ok' => true]);
    }

    public static function find(int $userId, int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM todos WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Task not found', 404);
        }
        return $row;
    }

    private static function owned(int $userId, int $id): void
    {
        self::find($userId, $id);
    }

    private static function sanitize(array $body, bool $creating): array
    {
        $title = isset($body['title']) ? trim((string) $body['title']) : null;
        if ($creating && ($title === null || $title === '')) {
            Response::error('Task title is required');
        }
        if ($title !== null && ($title === '' || strlen($title) > 255)) {
            Response::error('Task title must be between 1 and 255 characters');
        }
        $priority = $body['priority'] ?? ($creating ? 'medium' : null);
        if ($priority !== null && !in_array($priority, ['low', 'medium', 'high'], true)) {
            Response::error('Invalid priority');
        }
        $status = $body['status'] ?? ($creating ? 'open' : null);
        if ($status !== null && !in_array($status, ['open', 'doing', 'done'], true)) {
            Response::error('Invalid status');
        }
        $dueDate = $body['due_date'] ?? null;
        if ($dueDate === '') {
            $dueDate = null;
        } elseif ($dueDate !== null && !self::validDate($dueDate)) {
            Response::error('Due date must be a valid YYYY-MM-DD date');
        }
        $dueTime = $body['due_time'] ?? null;
        if ($dueTime === '') {
            $dueTime = null;
        } elseif ($dueTime !== null && !self::validTime($dueTime)) {
            Response::error('Due time must be a valid 24-hour time');
        }
        $notes = isset($body['notes']) ? (string) $body['notes'] : null;
        if ($notes !== null && strlen($notes) > 65535) {
            Response::error('Notes must be 65,535 characters or fewer');
        }
        $category = isset($body['category']) ? trim((string) $body['category']) : null;
        if ($category !== null && strlen($category) > 64) {
            Response::error('Category must be 64 characters or fewer');
        }
        if ($category === '') {
            $category = 'General';
        }
        $parent = $body['parent_id'] ?? null;
        if ($parent !== null && $parent !== '') {
            $parent = filter_var($parent, FILTER_VALIDATE_INT);
            if ($parent === false || $parent < 1) {
                Response::error('Invalid parent task');
            }
        } else {
            $parent = null;
        }
        return [
            'title' => $title,
            'notes' => $notes,
            'category' => $category ?? ($creating ? 'General' : null),
            'priority' => $priority,
            'due_date' => $dueDate,
            'due_date_set' => $creating || array_key_exists('due_date', $body),
            'due_time' => $dueTime,
            'due_time_set' => $creating || array_key_exists('due_time', $body),
            'status' => $status,
            'parent_id' => $parent === null ? null : (int) $parent,
        ];
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function validTime(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value);
    }
}
