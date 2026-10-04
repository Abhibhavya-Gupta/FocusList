<?php
declare(strict_types=1);

final class AiService
{
    public static function parse(string $text, ?string $referenceDate = null): array
    {
        $dateContext = $referenceDate === null ? '' : "The user's local date is {$referenceDate}. ";
        $prompt = $dateContext . "Turn this into one todo as JSON with keys: title, notes, category, priority (low|medium|high), due_date (YYYY-MM-DD or null), due_time (HH:MM or null). Categories: Work, Home, Health, Money, People, Learning, General. Text: " . $text;
        $parsed = self::askJson($prompt);
        if ($parsed) {
            $base = HeuristicAi::parse($text, $referenceDate);
            $title = $parsed['title'] ?? null;
            $notes = $parsed['notes'] ?? null;
            $category = $parsed['category'] ?? null;
            $priority = $parsed['priority'] ?? null;
            $date = $parsed['due_date'] ?? null;
            $time = $parsed['due_time'] ?? null;
            return [
                'title' => is_string($title) && trim($title) !== '' && strlen($title) <= 255
                    ? trim($title)
                    : $base['title'],
                'notes' => is_string($notes) ? substr($notes, 0, 60000) : $base['notes'],
                'category' => is_string($category) && trim($category) !== '' && strlen($category) <= 64
                    ? trim($category)
                    : $base['category'],
                'priority' => in_array($priority, ['low', 'medium', 'high'], true)
                    ? $priority
                    : $base['priority'],
                'due_date' => $date === null || self::validDate($date) ? $date : $base['due_date'],
                'due_time' => $time === null || self::validTime($time) ? $time : $base['due_time'],
                'status' => 'open',
                'provider' => $parsed['_provider'],
            ];
        }
        return HeuristicAi::parse($text, $referenceDate);
    }

    public static function breakdown(string $title): array
    {
        $prompt = "Break this task into 3 to 5 short actionable steps. Return JSON {\"steps\":[\"...\"]}. Task: " . $title;
        $parsed = self::askJson($prompt);
        if ($parsed && isset($parsed['steps']) && is_array($parsed['steps'])) {
            $steps = array_map('trim', array_filter(
                $parsed['steps'],
                fn($step) => is_string($step) && trim($step) !== '' && strlen($step) <= 255
            ));
            $steps = array_values($steps);
            if ($steps !== []) {
                return ['steps' => array_slice($steps, 0, 5), 'provider' => $parsed['_provider']];
            }
        }
        return HeuristicAi::breakdown($title);
    }

    public static function plan(array $todos, string $name): array
    {
        $titles = [];
        foreach ($todos as $t) {
            if (($t['status'] ?? '') === 'done') {
                continue;
            }
            $titles[] = ($t['priority'] ?? 'medium') . ' | ' . ($t['due_date'] ?? 'no date') . ' | ' . $t['title'];
        }
        $prompt = "You are a calm productivity coach for {$name}. Open tasks:\n" . implode("\n", $titles) . "\nReturn JSON {\"headline\":\"...\",\"plan\":[\"Now: ...\",\"Afternoon: ...\"],\"nudge\":\"...\"} Keep it short and kind.";
        $parsed = self::askJson($prompt);
        if ($parsed && isset($parsed['plan']) && is_array($parsed['plan'])) {
            $lines = array_values(array_filter($parsed['plan'], fn($line) => is_string($line) && trim($line) !== ''));
            if ($lines !== []) {
                return [
                    'headline' => is_string($parsed['headline'] ?? null) ? $parsed['headline'] : 'Here is a simple day.',
                    'plan' => array_slice($lines, 0, 5),
                    'nudge' => is_string($parsed['nudge'] ?? null) ? $parsed['nudge'] : '',
                    'provider' => $parsed['_provider'],
                ];
            }
        }
        return HeuristicAi::plan($todos, $name);
    }

    public static function suggest(array $todos): array
    {
        $titles = array_column(array_filter($todos, fn($t) => ($t['status'] ?? '') !== 'done'), 'title');
        $prompt = "Suggest 3 helpful next actions as JSON {\"suggestions\":[\"...\"]} given tasks: " . implode('; ', $titles);
        $parsed = self::askJson($prompt);
        if ($parsed && isset($parsed['suggestions']) && is_array($parsed['suggestions'])) {
            $ideas = array_values(array_filter($parsed['suggestions'], fn($idea) => is_string($idea) && trim($idea) !== ''));
            if ($ideas !== []) {
                return ['suggestions' => array_slice($ideas, 0, 3), 'provider' => $parsed['_provider']];
            }
        }
        return HeuristicAi::suggest($todos);
    }

    private static function providers(): array
    {
        $pref = Env::get('AI_PROVIDER', 'auto');
        if ($pref === 'local') {
            return [];
        }
        $order = $pref === 'gemini' ? ['gemini', 'openai'] : ['openai', 'gemini'];
        return array_values(array_filter($order, fn($provider) => Env::get(
            $provider === 'openai' ? 'OPENAI_API_KEY' : 'GEMINI_API_KEY'
        ) !== null));
    }

    private static function askJson(string $prompt): ?array
    {
        foreach (self::providers() as $provider) {
            $text = $provider === 'openai' ? self::openai($prompt) : self::gemini($prompt);
            if ($text === null) {
                continue;
            }
            $text = trim($text);
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?/i', '', $text) ?? $text;
                $text = preg_replace('/```$/', '', $text) ?? $text;
                $text = trim($text);
            }
            $data = json_decode($text, true);
            if (is_array($data)) {
                $data['_provider'] = $provider;
                return $data;
            }
            error_log('AI provider returned invalid JSON');
        }
        return null;
    }

    private static function openai(string $prompt): ?string
    {
        $key = Env::get('OPENAI_API_KEY');
        $payload = json_encode([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => 'Reply with JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        $result = self::http(
            'https://api.openai.com/v1/chat/completions',
            $payload ?: '{}',
            ['Authorization: Bearer ' . $key, 'Content-Type: application/json']
        );
        return $result['choices'][0]['message']['content'] ?? null;
    }

    private static function gemini(string $prompt): ?string
    {
        $key = Env::get('GEMINI_API_KEY');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode((string) $key);
        $payload = json_encode([
            'contents' => [['parts' => [['text' => "Reply with JSON only.\n" . $prompt]]]],
        ]);
        $result = self::http($url, $payload ?: '{}', ['Content-Type: application/json']);
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    private static function http(string $url, string $payload, array $headers): ?array
    {
        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]));
        if (!is_string($raw) || $raw === '') {
            error_log('AI provider request failed');
            return null;
        }
        foreach (array_reverse($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                if ((int) $match[1] >= 400) {
                    error_log('AI provider request returned HTTP ' . $match[1]);
                    return null;
                }
                break;
            }
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            error_log('AI provider returned an invalid response');
            return null;
        }
        return $data;
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
        return is_string($value) && (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
