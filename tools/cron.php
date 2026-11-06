<?php
declare(strict_types=1);

/**
 * CLI cron entry point.
 *
 * Example crontab (every 15 minutes):
 *   0,15,30,45 * * * * php /path/to/tools/cron.php >> /path/to/data/logs/cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/core/bootstrap.php';

echo json_encode(
    ['ok' => true, 'result' => \Core\Scheduler::run()],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
