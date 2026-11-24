<?php
declare(strict_types=1);

/**
 * Decides which open tasks need the project lead's attention and why.
 *
 * Pure rules: no database and no HTTP. The caller passes tasks, their comments
 * (ordered by id) and the current time, so the rules are easy to test and change.
 */
final class Attention
{
    public const DUE_SOON_HOURS = 48;

    /** Signal weights; the order of this list is also the order of reasons in a response. */
    public const WEIGHTS = [
        'needs_reply' => 40,
        'overdue' => 30,
        'blocked' => 20,
        'due_soon' => 10,
        'unassigned' => 10,
    ];

    private const LABELS = [
        'needs_reply' => 'Ждёт вашего ответа',
        'overdue' => 'Срок прошёл',
        'blocked' => 'Заблокирована',
        'due_soon' => 'Срок в ближайшие 48 ч',
        'unassigned' => 'Нет исполнителя',
    ];

    /** Phrases that report an obstacle or ask the lead for help. Case-insensitive, Unicode. */
    private const REQUEST_PATTERNS = [
        '/помо(щ|г|ч)/iu',
        '/нужн\w*\s+(\w+\s+)?решени/iu',
        '/не\s+(можем|могу|могут|сможем|получается)\s+продолж/iu',
        '/без\s+[^.!?]{1,60}?\s+не\s+продолж/iu',
        '/препятстви/iu',
        '/заблокир|блокер/iu',
        '/эскалир|эскалац/iu',
    ];

    /** Explicit statements that there is no problem; removed before matching requests. */
    private const NEGATIONS = [
        '/(препятстви|проблем|блокер|расхождени|вопрос)\w*\s+(пока\s+|уже\s+)?нет/iu',
        '/нет\s+(никаких\s+)?(препятстви|проблем|блокер|вопрос)\w*/iu',
        '/без\s+(препятстви|проблем|блокер)\w*/iu',
    ];

    /**
     * @param list<array<string,mixed>> $tasks open tasks of the lead's active projects
     * @param array<int, list<array<string,mixed>>> $commentsByTask comments with author, ordered by id
     * @param array<int, array{id:int,name:string}> $projectsById
     * @return list<array<string,mixed>>
     */
    public function evaluate(array $tasks, array $commentsByTask, array $projectsById, int $leadId, string $leadName, DateTimeImmutable $now): array
    {
        $items = [];
        foreach ($tasks as $task) {
            if ($task['status'] === 'done' || !isset($projectsById[$task['project_id']])) {
                continue;
            }
            $codes = [];
            $evidence = $this->unansweredRequest($commentsByTask[$task['id']] ?? [], $leadId, $leadName);
            if ($evidence !== null) {
                $codes[] = 'needs_reply';
            }
            $due = $task['due_at'] === null ? null : new DateTimeImmutable($task['due_at'], new DateTimeZone('UTC'));
            if ($due !== null && $due < $now) {
                $codes[] = 'overdue';
            }
            if ($task['status'] === 'blocked') {
                $codes[] = 'blocked';
            }
            if ($due !== null && $due >= $now && $due <= $now->modify('+' . self::DUE_SOON_HOURS . ' hours')) {
                $codes[] = 'due_soon';
            }
            if ($task['assignee_id'] === null) {
                $codes[] = 'unassigned';
            }
            if (!$codes) {
                continue;
            }
            $items[] = [
                'task' => $task,
                'project' => $projectsById[$task['project_id']],
                'score' => array_sum(array_map(fn(string $code) => self::WEIGHTS[$code], $codes)),
                'reasons' => array_map(fn(string $code) => ['code' => $code, 'label' => self::LABELS[$code]], $codes),
                'evidence' => $evidence,
            ];
        }
        usort($items, function (array $a, array $b): int {
            return [$b['score'], $a['task']['due_at'] === null, $a['task']['due_at'], $a['task']['id']]
                <=> [$a['score'], $b['task']['due_at'] === null, $b['task']['due_at'], $b['task']['id']];
        });
        return $items;
    }

    /**
     * The latest request from a team member written after the lead's last comment.
     * Once the lead answers in the task, older requests no longer count.
     */
    public function unansweredRequest(array $comments, int $leadId, string $leadName): ?array
    {
        $found = null;
        foreach ($comments as $comment) {
            if ((int) $comment['user_id'] === $leadId) {
                $found = null;
                continue;
            }
            if ($this->isRequest((string) $comment['body'], $leadName)) {
                $found = [
                    'comment_id' => $comment['id'],
                    'author' => $comment['author'],
                    'body' => $comment['body'],
                    'created_at' => $comment['created_at'],
                ];
            }
        }
        return $found;
    }

    public function isRequest(string $text, string $leadName = ''): bool
    {
        $text = preg_replace(self::NEGATIONS, ' ', $text) ?? $text;
        foreach (self::REQUEST_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        // Addressing the lead by first name ("Анна, помоги…", "@Анна") counts as a request.
        $first = trim(preg_split('/[\s·]+/u', $leadName)[0] ?? '');
        return $first !== '' && preg_match('/(^|[^\p{L}])@?' . preg_quote($first, '/') . '(?=[^\p{L}]|$)/iu', $text) === 1;
    }
}
