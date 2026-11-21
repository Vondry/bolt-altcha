<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps every record in memory, for assertions. Reset by nothing: one kernel per test.
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
        ];
    }

    /**
     * @return list<string> the messages logged at `$level`
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
