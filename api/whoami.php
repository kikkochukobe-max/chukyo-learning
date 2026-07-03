<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$actor = current_actor();

if (!$actor) {
    json_response(['ok' => false]);
}

json_response(['ok' => true, 'actor' => $actor]);
