<?php
declare(strict_types=1);

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
// The attention rules depend on "now": pin the clock to the seed time for repeatable checks.
$environment['APP_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function attention(int $user = 1): array
{
    [$status, $response] = request('GET', '/api/attention', null, $user);
    if ($status !== 200) {
        throw new RuntimeException("FAIL: attention for user $user returned $status");
    }
    $items = [];
    foreach ($response['data']['items'] as $item) {
        $items[$item['task']['id']] = $item;
    }
    return [$response['data'], $items];
}

function codes(array $item): array
{
    return array_column($item['reasons'], 'code');
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');

    // Attention desk: read-only checks on the seed, before any data changes.
    require_once __DIR__ . '/../src/Attention.php';
    $rules = new Attention();
    check($rules->isRequest('Нужна помощь руководителя с выбором.'), 'rules: help request detected');
    check($rules->isRequest('Без доступа не продолжить.'), 'rules: blocked work detected');
    check(!$rules->isRequest('Макет согласован, препятствий нет.'), 'rules: "препятствий нет" is not an obstacle');
    check(!$rules->isRequest('Сверка идёт по плану, расхождений пока нет.'), 'rules: plain status update is not a request');
    check($rules->isRequest('Препятствий нет, но нужна помощь с доступом.'), 'rules: negation does not hide a request in the same comment');
    check($rules->isRequest('Анна, посмотри, пожалуйста.', 'Анна · руководитель'), 'rules: addressing the lead by name is a request');
    check(!$rules->isRequest('Встреча с Анной Петровной в пятницу.', 'Анна · руководитель'), 'rules: other word forms of the name are not a request');
    [$summary, $items] = attention();
    check(array_column($summary['projects'], 'id') === [1, 2, 3], 'attention: Anna sees her active led projects only');
    check(array_keys($items) === [5, 1, 7, 2], 'attention: Anna gets tasks in priority order');
    check(codes($items[5]) === ['needs_reply', 'overdue', 'blocked'] && $items[5]['score'] === 90, 'attention: #5 waits for reply, overdue and blocked');
    check(codes($items[1]) === ['needs_reply', 'blocked', 'due_soon'], 'attention: #1 request, blocked and due soon');
    check(codes($items[7]) === ['needs_reply', 'unassigned'], 'attention: #7 found by free text without status or deadline');
    check($items[7]['evidence']['author'] === 'Борис · разработчик' && str_contains($items[7]['evidence']['body'], 'Анна, помоги'), 'attention: evidence quotes the request');
    check(codes($items[2]) === ['overdue'] && $items[2]['evidence'] === null, 'attention: #2 is only overdue');
    check(!isset($items[8]) && !isset($items[6]), 'attention: comments saying there is no problem are ignored');
    check(!isset($items[10]), 'attention: archived project excluded');
    check(!isset($items[11]), 'attention: project Anna does not belong to is not disclosed');
    check(!isset($items[3]) && !isset($items[9]), 'attention: done tasks excluded');
    [$summary, $items] = attention(3);
    check(array_column($summary['projects'], 'id') === [5] && array_keys($items) === [11], 'attention: Vera sees her own project only');
    [$summary, $items] = attention(2);
    check($summary['projects'] === [] && $items === [], 'attention: Boris leads no project and gets an empty list');
    check(request('GET', '/api/attention', null, 999)[0] === 401, 'attention: unknown persona rejected');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');
    // Reply flow: the lead's answer clears the request, a new request brings it back.
    [$status, $response] = request('POST', '/api/tasks/7/comments', ['body' => 'Беру на себя, согласую владельца до пятницы.']);
    check($status === 201 && $response['data']['author'] === 'Анна · руководитель', 'Anna answers in task #7');
    [, $items] = attention();
    check(codes($items[7]) === ['unassigned'] && $items[7]['evidence'] === null, "attention: lead's answer clears needs_reply");
    request('POST', '/api/tasks/7/comments', ['body' => 'Вторая команда не отвечает, нужна помощь.'], 2);
    [, $items] = attention();
    check(codes($items[7]) === ['needs_reply', 'unassigned'] && str_contains($items[7]['evidence']['body'], 'Вторая команда'), 'attention: new request after the answer raises the task again');
    [$status, $response] = request('GET', '/api/tasks/7');
    check($response['data']['version'] === 1, 'comments do not change task version');
    check(request('POST', '/api/tasks/7/comments', ['body' => 'x', 'task_id' => 1])[0] === 422, 'comment rejects unknown fields');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
