<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (!$app->environment('testing') || !str_ends_with((string) config('database.connections.pgsql.database'), '_test')
    || config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')) {
    fwrite(STDERR, 'Este ejecutor requiere una base PostgreSQL separada de pruebas.'); exit(2);
}
$data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$request = Request::create('/api/v1/intakes', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $data['key'],
    'REMOTE_ADDR' => $data['ip'],
], content: json_encode($data['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'id' => $body['data']['id'] ?? null,
    'reference' => $body['data']['reference'] ?? null, 'token_hash' => isset($body['data']['access_token']) ? hash('sha256', $body['data']['access_token']) : null], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
