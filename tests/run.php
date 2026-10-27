<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
use DB4D\{DB4DDriver, DB4DException, Parameters, Codec, Transport};

error_reporting(E_ALL);
set_error_handler(static function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$tests = [];
function test(string $name, Closure $test): void { $GLOBALS['tests'][$name] = $test; }
function same(mixed $actual, mixed $expected): void {
    if ($actual !== $expected) { throw new RuntimeException('Unexpected result: ' . var_export($actual, true) . ' versus ' . var_export($expected, true)); }
}
function fails(Closure $fn, string $state): DB4DException {
    try { $fn(); } catch (DB4DException $e) { same($e->getCode(), $state); return $e; }
    throw new RuntimeException('Expected DB4DException was not raised');
}
function login(): array { return ['command' => 'LOGIN', 'headers' => ['PROTOCOL-VERSION' => '0.1a', 'REPLY-WITH-BASE64-TEXT' => 'Y']]; }
function closeStep(int $id = 42): array { return ['command' => 'CLOSE-STATEMENT', 'headers' => ['STATEMENT-ID' => (string)$id]]; }
function update(string $sql, int $count = 0): array {
    return ['command' => 'EXECUTE-STATEMENT', 'sql' => $sql, 'response' => ['Result-Type' => 'Update-Count'], 'body' => base64_encode(pack('q', $count))];
}
function result(array $rows, array $types = ['VK_LONG', 'VK_STRING'], array $names = ['id', 'name'], int $total = -1, bool $base64 = false): array {
    $body = '';
    foreach ($rows as $row) {
        foreach ($row as $i => $value) {
            $body .= $value === null ? '0' : '1';
            if ($value === null) { continue; }
            $body .= match ($types[$i]) {
                'VK_LONG' => pack('V', $value), 'VK_WORD', 'VK_BYTE', 'VK_BOOLEAN' => pack('v', $value),
                'VK_LONG8', 'VK_DURATION' => pack('q', $value), 'VK_REAL' => pack('e', $value),
                'VK_STRING' => pack('V', -strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2) . mb_convert_encoding($value, 'UTF-16LE', 'UTF-8'),
                'VK_BLOB', 'VK_IMAGE' => pack('V', strlen($value)) . $value,
                'VK_TIMESTAMP', 'VK_TIME' => $value,
            };
        }
    }
    $aliases = implode(' ', array_map(static fn($n) => '[' . str_replace(']', ']]', $n) . ']', $names));
    return ['response' => ['Result-Type' => 'Result-Set', 'Statement-ID' => '42', 'Command-Count' => '1',
        'Row-Count' => (string)($total < 0 ? count($rows) : $total), 'Row-Count-Sent' => (string)count($rows),
        'Column-Count' => (string)count($types), 'Column-Types' => implode(' ', $types),
        $base64 ? 'Column-Aliases-Base64' : 'Column-Aliases' => $base64 ? base64_encode($aliases) : $aliases,
        'Column-Updateability' => implode(' ', array_fill(0, count($types), 'N'))], 'body' => base64_encode($body)];
}
function selectStep(string $sql, array $rows, array $types = ['VK_LONG', 'VK_STRING'], array $names = ['id', 'name'], int $total = -1): array {
    return ['command' => 'EXECUTE-STATEMENT', 'sql' => $sql] + result($rows, $types, $names, $total);
}
function peer(array $steps, Closure $fn, array $options = []): void {
    $spec = tempnam(sys_get_temp_dir(), 'db4d-wire-'); chmod($spec, 0600);
    file_put_contents($spec, json_encode($steps, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, __DIR__ . '/server.php', $spec], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start local peer'); }
    fclose($pipes[0]); stream_set_timeout($pipes[1], 5);
    $endpoint = trim((string)fgets($pipes[1]));
    try {
        if (!$endpoint) { throw new RuntimeException('Peer did not start'); }
        $fn((int)substr(strrchr($endpoint, ':'), 1), $options);
    } finally {
        $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process); unlink($spec);
        if ($code !== 0) { throw new RuntimeException('Local peer failed: ' . $error); }
    }
}
function connectPeer(int $port, array $options = []): DB4DDriver { return new DB4DDriver('127.0.0.1', $port, 'fixture-user', 'test-password', $options); }

foreach (["price $1", ':QUOT:', "line1\r\nline2", "l'été ? \\ $2", "\r\nQUIT\r\n\r\n", '日本語 🎉'] as $value) {
    test('literal preservation ' . bin2hex($value), static function() use ($value) {
        same(Parameters::render('SELECT ?', [$value]), "SELECT '" . str_replace("'", "''", $value) . "'");
    });
}
test('lexer quotes identifiers comments and named reuse', static function() {
    same(Parameters::render("SELECT '?', \"?\", [a?]],b], :n, :n -- :ignored ?\n/* ? /* :ignored */ */", ['n' => 'ok']),
        "SELECT '?', \"?\", [a?]],b], 'ok', 'ok' -- :ignored ?\n/* ? /* :ignored */ */");
});
test('lexer positional ignores strings and escaped apostrophes', static function() { same(Parameters::render("SELECT 'it''s ?', ?, ?", [3, null]), "SELECT 'it''s ?', 3, NULL"); });
foreach ([['SELECT ?', [], 'HY093'], ['SELECT 1', [1], 'HY093'], ['SELECT ?, :x', [0 => 1, 'x' => 2], 'HY093'], ["SELECT 'x", [], '42000'], ['SELECT /*x', [], '42000'], ['SELECT 1; DELETE FROM t', [], 'IM001']] as $i => [$sql, $values, $state]) {
    test('reject invalid placeholders or SQL ' . $i, static fn() => fails(static fn() => Parameters::render($sql, $values), $state));
}
test('types null boolean float integer and binary', static function() {
    same(Parameters::render('SELECT ?, ?, ?, ?, ?', [null, true, -1, 1.25, "\0\xff"], [4 => PDO::PARAM_LOB]), "SELECT NULL, CAST(1 AS BOOLEAN), -1, 1.25, X'00ff'");
    same(Parameters::literal('00042', PDO::PARAM_INT), '00042');
    same(Parameters::literal(true, PDO::PARAM_STR), "'1'");
    fails(static fn() => Parameters::literal('1 OR 1=1', PDO::PARAM_INT), '22018');
    fails(static fn() => Parameters::literal(INF), '22003');
    fails(static fn() => Parameters::literal("\xff"), '22021');
    fails(static fn() => Parameters::literal("a\0b"), '22021');
    fails(static fn() => Parameters::literal([]), '22021');
});
test('normalizing parameter names rejects aliases', static function() {
    same(Parameters::normalize([':a' => 1]), ['a' => 1]);
    fails(static fn() => Parameters::normalize([':a' => 1, 'a' => 2]), 'HY093');
    fails(static fn() => Parameters::normalize(['::a' => 1]), 'HY093');
});
foreach ([['ffffffff', -1], ['00000080', -2147483648], ['ffffff7f', 2147483647]] as [$hex, $value]) {
    test('signed32 ' . $hex, static fn() => same(Codec::signed32(hex2bin($hex)), $value));
}
foreach ([['ffffffffffffffff', -1], ['0000000000000080', PHP_INT_MIN], ['ffffffffffffff7f', PHP_INT_MAX]] as [$hex, $value]) {
    test('signed64 ' . $hex, static fn() => same(Codec::signed64(hex2bin($hex)), $value));
}
test('PDOException-compatible safe errors', static function() { $e = new DB4DException('fixture', '42000', 123); same($e instanceof PDOException, true); same($e->errorInfo, ['42000', 123, 'fixture']); });
test('autoload precedes legacy Magento-style fallback', static function() {
    $program = 'spl_autoload_register(static function($name){ throw new RuntimeException("legacy fallback"); }); require ' . var_export(dirname(__DIR__) . '/autoload.php', true) . '; if (!class_exists("DB4D\\\\DB4DDriver")) { exit(1); }';
    $child = proc_open([PHP_BINARY, '-r', $program], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    same(proc_close($child), 0); same($out, ''); same($err, '');
});

test('login zero update binary body and following query', static function() {
    peer([login(), update('UPDATE t SET x=1', 0), selectStep('SELECT x', [[1]], ['VK_LONG'], ['x']), closeStep()], static function($port) {
        $db = connectPeer($port); same($db->exec('UPDATE t SET x=1'), 0);
        $s = $db->query('SELECT x'); same($s->fetchColumn(), 1); same($s->fetchColumn(), false); $s->closeCursor(); $db->close(); $db->close();
    });
});
test('empty SELECT is successful and consumable repeatedly', static function() {
    peer([login(), selectStep('SELECT empty', []), closeStep()], static function($port) {
        $db = connectPeer($port); $s = $db->prepare('SELECT empty'); same($s->execute(), true); same($s->rowCount(), 0);
        same($s->fetch(PDO::FETCH_ASSOC), false); same($s->fetchAll(), []); same(iterator_to_array($s), []); $s->closeCursor();
    });
});
test('pagination never re-executes and cursor closes once', static function() {
    $first = selectStep('SELECT paged', [[1, 'one'], [2, 'two']], total: 5); $first['fragment'] = true;
    $second = ['command' => 'FETCH-RESULT', 'headers' => ['FIRST-ROW-INDEX' => '2', 'LAST-ROW-INDEX' => '3', 'STATEMENT-ID' => '42', 'COMMAND-INDEX' => '000']] + result([[3, null], [4, 'four']], total: 5);
    $third = ['command' => 'FETCH-RESULT', 'headers' => ['FIRST-ROW-INDEX' => '4', 'LAST-ROW-INDEX' => '4']] + result([[5, 'five']], total: 5);
    peer([login(), $first, $second, $third, closeStep()], static function($port) {
        $db = connectPeer($port, ['page_size' => 2]); $db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $s = $db->query('SELECT paged'); same($s->rowCount(), 5); same($s->columnCount(), 2);
        same($s->fetch(PDO::FETCH_ASSOC), ['ID' => 1, 'NAME' => 'one']);
        same($s->fetchAll(PDO::FETCH_NUM), [[2, 'two'], [3, null], [4, 'four'], [5, 'five']]);
        same($s->fetchAll(PDO::FETCH_ASSOC), []); $s->closeCursor(); $s->closeCursor();
    });
});
test('fetch modes do not mutate rows; column consumes one row', static function() {
    peer([login(), selectStep('SELECT modes', [[0, 'first'], [1, 'second'], [2, 'third'], [3, 'fourth']]), closeStep()], static function($port) {
        $db = connectPeer($port); $s = $db->query('SELECT modes');
        same($s->fetchColumn(1), 'first'); same($s->fetch(PDO::FETCH_NUM), [1, 'second']);
        same($s->fetch(PDO::FETCH_ASSOC), ['id' => 2, 'name' => 'third']);
        same($s->fetch(), [0 => 3, 1 => 'fourth', 'id' => 3, 'name' => 'fourth']);
        same($s->fetchRow(), null); $s->closeCursor();
    });
});
test('named and reference binding with re-execution and reset', static function() {
    peer([login(), update("UPDATE t SET x='first'"), update("UPDATE t SET x='second'"), update("UPDATE t SET x='third'")], static function($port) {
        $db = connectPeer($port); $s = $db->prepare('UPDATE t SET x=:x'); $v = 'first'; $s->bindParam(':x', $v);
        same($s->execute(), true); $v = 'second'; same($s->execute(), true); same($s->execute([':x' => 'third']), true);
        same($s->getRawSql(), "UPDATE t SET x='second'");
    });
});
test('wire parameters retain dollars CRLF and quotes', static function() {
    $value = "price $1 ? :QUOT: l'été\r\n日本語 🎉";
    $sql = "INSERT INTO t VALUES ('" . str_replace("'", "''", $value) . "')";
    peer([login(), update($sql, 1)], static function($port) use ($value) { $db = connectPeer($port); $s = $db->prepare('INSERT INTO t VALUES (?)'); same($s->execute([$value]), true); same($s->rowCount(), 1); });
});
test('LOB binding does not consume stream until execute', static function() {
    peer([login(), update("INSERT INTO t VALUES (X'00ff')", 1)], static function($port) {
        $db = connectPeer($port); $s = $db->prepare('INSERT INTO t VALUES (?)'); $stream = fopen('php://temp', 'w+b'); fwrite($stream, "\0\xff"); rewind($stream);
        $s->bindValue(1, $stream, PDO::PARAM_LOB); same(ftell($stream), 0); $s->execute(); same(ftell($stream), 2); fclose($stream);
    });
});
test('transactions change state after successful response only', static function() {
    $err = ['command' => 'EXECUTE-STATEMENT', 'sql' => 'COMMIT', 'status' => 'ERROR', 'response' => ['Error-Code' => '123', 'Error-Description' => 'private echoed fixture']];
    peer([login(), update('START'), $err, update('ROLLBACK'), update('START'), update('COMMIT')], static function($port) {
        $db = connectPeer($port); same($db->inTransaction(), false); $db->beginTransaction(); same($db->inTransaction(), true);
        fails(static fn() => $db->beginTransaction(), '25000'); $e = fails(static fn() => $db->commit(), '42000');
        same(str_contains($e->getMessage(), 'private'), false); same($db->inTransaction(), true);
        $db->rollBack(); same($db->inTransaction(), false); $db->beginTransaction(); $db->commit(); same($db->inTransaction(), false);
        fails(static fn() => $db->commit(), '25000');
    });
});
test('server SQL error updates both errorInfo and permits next request', static function() {
    peer([login(), ['command' => 'EXECUTE-STATEMENT', 'status' => 'ERROR', 'response' => ['Error-Code' => '1600', 'Error-Description-Base64' => base64_encode('sensitive')]], update('UPDATE recovered', 2)], static function($port) {
        $db = connectPeer($port); $s = $db->prepare('bad SQL'); $e = fails(static fn() => $s->execute(), '42000'); same($s->errorInfo(), $e->errorInfo); same($db->errorInfo(), $e->errorInfo);
        same($db->exec('UPDATE recovered'), 2); same($db->errorCode(), '00000');
    });
});
test('login error is catchable and destructor is harmless', static function() {
    peer([['command' => 'LOGIN', 'status' => 'ERROR', 'response' => ['Error-Code' => '901', 'Error-Description' => 'denied']]], static fn($port) => fails(static fn() => connectPeer($port), '28000'));
});
test('timeout closes connection and never retries', static function() {
    peer([login(), ['command' => 'EXECUTE-STATEMENT', 'sleep_ms' => 150, 'disconnect' => true]], static function($port) {
        $db = connectPeer($port, ['read_timeout' => 0.05]); $start = microtime(true); fails(static fn() => $db->exec('UPDATE once'), 'HYT00');
        same($db->isOpen(), false); fails(static fn() => $db->exec('UPDATE once'), '08003'); same(microtime(true) - $start < 1, true);
    });
});
test('EOF halfway through field closes channel without looping', static function() {
    $step = selectStep('SELECT truncated', [[1, 'value']]); $step['truncate'] = -1;
    peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT truncated'), '08006'); same($db->isOpen(), false); });
});
test('response identifier mismatch fails closed', static function() {
    peer([login(), ['command' => 'EXECUTE-STATEMENT', 'wrong_id' => true]], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT mismatch'), '08S01'); same($db->isOpen(), false); });
});
test('unsupported proprietary float fails explicitly instead of corrupting values', static function() {
    $step = ['command' => 'EXECUTE-STATEMENT', 'response' => ['Result-Type' => 'Result-Set', 'Column-Count' => '1', 'Column-Types' => 'VK_FLOAT', 'Column-Aliases' => '[amount]', 'Column-Updateability' => 'N', 'Row-Count' => '1', 'Row-Count-Sent' => '1'], 'body' => base64_encode('1')];
    peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT amount'), 'IM001'); same($db->isOpen(), false); });
});
test('wire types signed boundaries unicode null empty bytes milliseconds', static function() {
    $types = ['VK_WORD', 'VK_LONG', 'VK_LONG8', 'VK_LONG8', 'VK_BOOLEAN', 'VK_REAL', 'VK_STRING', 'VK_STRING', 'VK_STRING', 'VK_BLOB', 'VK_BLOB', 'VK_TIMESTAMP'];
    $names = ['word', 'long', 'max', 'min', 'bool', 'real', 'unicode', 'empty', 'nil', 'bytes', 'emptyBlob', 'date'];
    $values = [-32768, -2147483648, PHP_INT_MAX, PHP_INT_MIN, 1, -1.25, 'été 日本語 🎉', '', null, "\0\xff", '', pack('vCCV', 2026, 10, 27, 3661123)];
    $step = ['command' => 'EXECUTE-STATEMENT'] + result([$values], $types, $names, base64: true);
    peer([login(), $step, closeStep()], static function($port) use ($values) {
        $db = connectPeer($port); $s = $db->query('SELECT types'); $row = $s->fetch(PDO::FETCH_NUM);
        $values[4] = true; $values[11] = '2026/10/27 01:01:01.123'; same($row, $values); $s->closeCursor();
    });
});
test('stringified fetch and Unicode column casing', static function() {
    peer([login(), selectStep('SELECT strings', [[false, 3, null]], ['VK_BOOLEAN', 'VK_LONG', 'VK_STRING'], ['Été', 'id', 'nil']), closeStep()], static function($port) {
        $db = connectPeer($port, [PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_CASE => PDO::CASE_LOWER]);
        $s = $db->query('SELECT strings', PDO::FETCH_ASSOC); same($s->fetch(), ['été' => '0', 'id' => '3', 'nil' => null]); $s->closeCursor();
    });
});
test('legacy timestamp format uses integral time components', static function() {
    peer([login(), selectStep('SELECT legacydate', [[pack('vCCV', 2026, 10, 27, 3661000)]], ['VK_TIME'], ['date']), closeStep()], static function($port) {
        $db = connectPeer($port, ['timestamp_format' => 'legacy']); $s = $db->query('SELECT legacydate'); same($s->fetchColumn(), '27.10.2026 01:01:01'); $s->closeCursor();
    });
});
test('unsupported attributes modes and APIs are explicit', static function() {
    peer([login()], static function($port) {
        $db = connectPeer($port); fails(static fn() => $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT), 'IM001');
        fails(static fn() => $db->setAttribute(PDO::ATTR_PERSISTENT, true), 'IM001'); fails(static fn() => $db->lastInsertId(), 'IM001');
        $s = $db->prepare('SELECT ?'); fails(static fn() => $s->bindValue(0, 'x'), 'HY093');
        fails(static fn() => $s->setFetchMode(PDO::FETCH_CLASS), 'IM001'); fails(static fn() => $s->fetch(PDO::FETCH_NUM), 'HY010');
    });
});
test('DSN recognizes only 4D host port and UTF-8', static function() {
    peer([login()], static function($port) {
        $db = DB4DDriver::fromDsn('4D:host=127.0.0.1;port=' . $port . ';charset=UTF-8', 'fixture-user', 'test-password'); same($db->getAttribute(PDO::ATTR_DRIVER_NAME), '4D');
    });
    foreach (['mysql:host=localhost', '4D:host=localhost;host=other', '4D:host=localhost;port=x', '4D:dbname=a'] as $dsn) { fails(static fn() => DB4DDriver::fromDsn($dsn, '', ''), 'IM002'); }
    fails(static fn() => DB4DDriver::fromDsn('4D:host=localhost;charset=ASCII', '', ''), 'IM001');
});

test('multiple prepared cursors use independent IDs in execution order', static function() {
    $a = selectStep('SELECT first', [[1]], ['VK_LONG'], ['n']); $a['response']['Statement-ID'] = '70';
    $b = selectStep('SELECT second', [[2]], ['VK_LONG'], ['n']); $b['response']['Statement-ID'] = '71';
    peer([login(), $b, $a, closeStep(70), closeStep(71)], static function($port) {
        $db = connectPeer($port); $a = $db->prepare('SELECT first'); $b = $db->prepare('SELECT second');
        $b->execute(); $a->execute(); same($a->fetchColumn(), 1); same($b->fetchColumn(), 2); $a->closeCursor(); $b->closeCursor();
    });
});
test('zero initial page fetches the existing cursor and supports omitted page metadata', static function() {
    $first = selectStep('SELECT delayedpage', [], ['VK_LONG'], ['n'], 2);
    $next = ['command' => 'FETCH-RESULT', 'headers' => ['FIRST-ROW-INDEX' => '0', 'LAST-ROW-INDEX' => '1'], 'response' => [], 'body' => base64_encode('1' . pack('V', 5) . '1' . pack('V', 6))];
    peer([login(), $first, $next, closeStep()], static function($port) {
        $db = connectPeer($port, ['page_size' => 2]); $s = $db->query('SELECT delayedpage'); same($s->fetchAll(PDO::FETCH_COLUMN), [5, 6]); $s->closeCursor();
    });
});
test('boolean column iteration preserves false and null values', static function() {
    peer([login(), selectStep('SELECT bools', [[false], [null], [true]], ['VK_BOOLEAN'], ['b']), closeStep()], static function($port) {
        $db = connectPeer($port); $s = $db->query('SELECT bools', PDO::FETCH_COLUMN); same(iterator_to_array($s), [false, null, true]); $s->closeCursor();
    });
});
test('fetchAll selected column and object fetch preserve remaining rows', static function() {
    peer([login(), selectStep('SELECT objects', [[1, 'a'], [2, 'b'], [3, 'c']]), closeStep()], static function($port) {
        $db = connectPeer($port); $s = $db->query('SELECT objects'); same((array)$s->fetch(PDO::FETCH_OBJ), ['id' => 1, 'name' => 'a']);
        same($s->fetchAll(PDO::FETCH_COLUMN, 1), ['b', 'c']); $s->closeCursor();
    });
});
test('cursor re-execution closes previous result exactly once', static function() {
    peer([login(), selectStep('SELECT again', [[1, 'a']]), closeStep(), selectStep('SELECT again', [[2, 'b']]), closeStep()], static function($port) {
        $db = connectPeer($port); $s = $db->prepare('SELECT again'); $s->execute(); same($s->fetchColumn(), 1); $s->execute(); same($s->fetchColumn(), 2); $s->closeCursor();
    });
});
test('closeCursor before exhaustion consumes no additional pages', static function() {
    peer([login(), selectStep('SELECT closeearly', [[1, 'one']], total: 1000), closeStep(), update('UPDATE afterclose', 1)], static function($port) {
        $db = connectPeer($port, ['page_size' => 1]); $s = $db->query('SELECT closeearly'); $s->closeCursor(); same($s->fetchAll(), []); same($db->exec('UPDATE afterclose'), 1);
    });
});
foreach ([
    'negative row count' => ['Row-Count' => '-1'],
    'overflow row count' => ['Row-Count' => '9223372036854775808'],
    'too many page rows' => ['Row-Count-Sent' => '101', 'Row-Count' => '101'],
    'wrong column count' => ['Column-Count' => '3'],
    'duplicate canonical header' => ['Column-Aliases-Base64' => base64_encode('[id] [name]')],
    'broken base64 header' => ['Column-Aliases-Base64' => '!!!'],
] as $name => $headers) {
    test('reject malformed response: ' . $name, static function() use ($headers) {
        $step = selectStep('SELECT malformed', []); $step['response'] = array_replace($step['response'], $headers);
        peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT malformed'), '08S01'); same($db->isOpen(), false); });
    });
}
test('field byte bound rejects oversized allocation before reading', static function() {
    $step = selectStep('SELECT huge', [['']], ['VK_STRING'], ['text']); $step['body'] = base64_encode('1' . hex2bin('00000080'));
    peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT huge'), '08S01'); });
});
test('page byte bound covers cumulative small fields', static function() {
    peer([login(), selectStep('SELECT pagelimit', [[1, 'four']])], static function($port) {
        $db = connectPeer($port, ['max_page_bytes' => 8]); fails(static fn() => $db->query('SELECT pagelimit'), '08S01');
    });
});
test('invalid cell and unsupported multiple results close channel', static function() {
    $step = selectStep('SELECT invalidcell', [[1, 'a']]); $step['body'] = base64_encode('2' . pack('q', 17));
    peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT invalidcell'), '08S01'); });
    $step['response']['Command-Count'] = '2';
    peer([login(), $step], static function($port) { $db = connectPeer($port); fails(static fn() => $db->query('SELECT invalidcell'), 'IM001'); });
});
test('updateable row identifier is consumed separately from user columns', static function() {
    $step = selectStep('SELECT updateable', [[9]], ['VK_LONG'], ['_ID']); $step['response']['Column-Updateability'] = 'Y';
    $step['body'] = base64_encode('1' . pack('V', 1234) . '1' . pack('V', 9));
    peer([login(), $step, closeStep()], static function($port) { $db = connectPeer($port); $s = $db->query('SELECT updateable'); same($s->fetch(PDO::FETCH_ASSOC), ['_ID' => 9]); $s->closeCursor(); });
});
test('very large request is fully written without truncation', static function() {
    $sql = "INSERT INTO t VALUES ('" . str_repeat('X', 300000) . "')";
    peer([login(), update($sql, 1)], static function($port) use ($sql) { $db = connectPeer($port); same($db->exec($sql), 1); });
});
test('interruption during transaction leaves outcome explicit and never reconnects', static function() {
    peer([login(), update('START'), ['command' => 'EXECUTE-STATEMENT', 'disconnect' => true]], static function($port) {
        $db = connectPeer($port); $db->beginTransaction(); $e = fails(static fn() => $db->exec('UPDATE once'), '08006');
        same(str_contains($e->getMessage(), 'outcome may be unknown'), true); same($db->inTransaction(), false); fails(static fn() => $db->commit(), '25000');
    });
});

/** A deterministic stream wrapper forces short writes, independent of kernel buffering. */
class ShortWriteFixture
{
    public $context;
    public static string $written = '';
    public function stream_open($path, $mode, $options, &$openedPath): bool { return true; }
    public function stream_write($data): int { $n = min(3, strlen($data)); self::$written .= substr($data, 0, $n); return $n; }
    public function stream_set_option($option, $arg1, $arg2): bool { return true; }
    public function stream_close(): void {}
}
test('short writes continue until the exact bytes are sent', static function() {
    stream_wrapper_register('db4dshort', ShortWriteFixture::class);
    $stream = fopen('db4dshort://fixture', 'w');
    $reflection = new ReflectionClass(Transport::class); $transport = $reflection->newInstanceWithoutConstructor();
    $reflection->getConstructor()->invoke($transport, $stream, 1.0);
    $bytes = str_repeat('abcdef', 100); $transport->write($bytes); same(ShortWriteFixture::$written, $bytes); $transport->close();
    stream_wrapper_unregister('db4dshort');
});

$passed = 0; $failed = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo 'PASS ', $name, "\n"; $passed++; }
    catch (Throwable $e) { echo 'FAIL ', $name, ': ', get_class($e), ': ', $e->getMessage(), "\n"; $failed++; }
}
echo json_encode(['php' => PHP_VERSION, 'passed' => $passed, 'failed' => $failed, 'production_connections' => 0]), "\n";
exit($failed ? 1 : 0);
