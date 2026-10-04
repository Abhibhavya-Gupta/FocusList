<?php
declare(strict_types=1);

final class HeuristicAi
{
    public static function parse(string $text, ?string $referenceDate = null): array
    {
        $original = trim($text);
        $lower = strtolower($original);
        $priority = 'medium';
        if (preg_match('/\b(urgent|asap|high|important)\b/', $lower)) {
            $priority = 'high';
        } elseif (preg_match('/\b(low|someday|later|whenever)\b/', $lower)) {
            $priority = 'low';
        }

        $category = 'General';
        $map = [
            'Work' => '/\b(email|meeting|slack|report|client|office|code|deploy|invoice)\b/',
            'Home' => '/\b(laundry|dishes|clean|grocer|rent|repair|kitchen)\b/',
            'Health' => '/\b(gym|doctor|dentist|run|walk|meditat|sleep|vitamin)\b/',
            'Money' => '/\b(pay|bank|tax|bill|budget|salary)\b/',
            'People' => '/\b(call|mom|dad|friend|birthday|text|visit)\b/',
            'Learning' => '/\b(study|read|course|practice|learn)\b/',
        ];
        foreach ($map as $label => $rx) {
            if (preg_match($rx, $lower)) {
                $category = $label;
                break;
            }
        }

        $dueDate = null;
        $dueTime = null;
        $today = $referenceDate === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate);
        if ($today === false || $today->format('Y-m-d') !== $referenceDate) {
            $today = new DateTimeImmutable('today');
        }
        if (preg_match('/\btoday\b/', $lower)) {
            $dueDate = $today->format('Y-m-d');
        } elseif (preg_match('/\btomorrow\b/', $lower)) {
            $dueDate = $today->modify('+1 day')->format('Y-m-d');
        } elseif (preg_match('/\bnext week\b/', $lower)) {
            $dueDate = $today->modify('+7 days')->format('Y-m-d');
        } else {
            foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day) {
                if (preg_match('/\b' . $day . '\b/', $lower)) {
                    $due = new DateTimeImmutable($day);
                    if ($due <= $today) {
                        $due = $due->modify('+7 days');
                    }
                    $dueDate = $due->format('Y-m-d');
                    break;
                }
            }
        }
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $lower, $m)) {
            $hour = (int) $m[1];
            $min = $m[2] !== '' ? (int) $m[2] : 0;
            $ampm = $m[3];
            if ($hour >= 1 && $hour <= 12 && $min <= 59) {
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                }
                if ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }
                $dueTime = sprintf('%02d:%02d', $hour, $min);
                if ($dueDate === null) {
                    $dueDate = $today->format('Y-m-d');
                }
            }
        }

        $title = preg_replace('/\b(today|tomorrow|next week|monday|tuesday|wednesday|thursday|friday|saturday|sunday|urgent|asap|high(?: priority)?|low(?: priority)?|important|\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/i', '', $original) ?? $original;
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = preg_replace('/\b(at|by|on)\s*$/i', '', $title) ?? $title;

        return [
            'title' => $title !== '' ? $title : $original,
            'notes' => '',
            'category' => $category,
            'priority' => $priority,
            'due_date' => $dueDate,
            'due_time' => $dueTime,
            'status' => 'open',
            'provider' => 'local',
        ];
    }

    public static function breakdown(string $title): array
    {
        $base = trim($title);
        $steps = [
            'Define one clear outcome',
            'Gather anything you need first',
            'Do the smallest useful version',
            'Review and mark it done',
        ];
        if (preg_match('/\b(email|write|draft)\b/i', $base)) {
            $steps = ['Outline the point', 'Write a rough draft', 'Edit once', 'Send or schedule'];
        } elseif (preg_match('/\b(study|learn|course)\b/i', $base)) {
            $steps = ['Set a 25-minute block', 'Read or watch one section', 'Take 3 notes', 'Recall without looking'];
        } elseif (preg_match('/\b(clean|laundry|grocer)\b/i', $base)) {
            $steps = ['Set a 15-minute timer', 'Do the first visible pile', 'Put things away', 'Stop when the timer ends'];
        }
        return ['steps' => $steps, 'provider' => 'local'];
    }

    public static function plan(array $todos, string $name): array
    {
        $open = array_values(array_filter($todos, fn($t) => ($t['status'] ?? '') !== 'done' && empty($t['parent_id'])));
        usort($open, function ($a, $b) {
            $pr = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($pr[$a['priority'] ?? 'medium'] <=> $pr[$b['priority'] ?? 'medium']);
        });
        $focus = array_slice($open, 0, 3);
        $lines = [];
        foreach ($focus as $i => $t) {
            $when = $i === 0 ? 'Now' : ($i === 1 ? 'Afternoon' : 'Wrap-up');
            $lines[] = $when . ': ' . $t['title'];
        }
        if (!$lines) {
            $lines[] = 'Inbox is clear. Add one thing that would make tomorrow easier.';
        }
        $nudge = $focus[0]['title'] ?? 'one small task you can finish in 10 minutes';
        return [
            'headline' => 'Good day, ' . $name . '.',
            'plan' => $lines,
            'nudge' => 'Start with ' . $nudge . '.',
            'provider' => 'local',
        ];
    }

    public static function suggest(array $todos): array
    {
        $open = array_values(array_filter($todos, fn($t) => ($t['status'] ?? '') !== 'done' && empty($t['parent_id'])));
        $ideas = [];
        if (count($open) === 0) {
            $ideas = [
                'Write down the one thing you have been avoiding',
                'Schedule a 20-minute walk',
                'Prep tomorrow’s first task before you log off',
            ];
        } else {
            foreach (array_slice($open, 0, 3) as $t) {
                $ideas[] = 'Finish: ' . $t['title'];
            }
        }
        return ['suggestions' => $ideas, 'provider' => 'local'];
    }
}
