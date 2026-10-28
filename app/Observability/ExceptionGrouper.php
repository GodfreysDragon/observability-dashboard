<?php

namespace App\Observability;

use Throwable;

/**
 * Groups exceptions by where they come from rather than what they say: class plus the
 * first application frame. Messages often embed ids or values ("No query results for
 * model [Invoice] 4182"), so grouping on them would split one bug into thousands.
 */
final class ExceptionGrouper
{
    private const int MAX_FRAMES = 25;

    public function __construct(private readonly string $basePath) {}

    /**
     * @return array{fingerprint: string, class: string, message: string, file: string, line: int, trace: list<array{file: string, line: int, call: string, app: bool}>}
     */
    public function describe(Throwable $exception): array
    {
        $frames = $this->frames($exception);
        $origin = collect($frames)->first(fn (array $frame) => $frame['app']) ?? $frames[0];
        $class = $exception::class;

        return [
            'fingerprint' => sha1($class.'|'.$origin['file'].'|'.$origin['line']),
            'class' => $class,
            'message' => mb_substr($this->scrub($exception->getMessage()), 0, 2000),
            'file' => $origin['file'],
            'line' => $origin['line'],
            'trace' => $frames,
        ];
    }

    /**
     * @return list<array{file: string, line: int, call: string, app: bool}>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [[
            'file' => $this->relative($exception->getFile()),
            'line' => $exception->getLine(),
            'call' => 'throw '.class_basename($exception),
            'app' => $this->isApp($exception->getFile()),
        ]];

        foreach ($exception->getTrace() as $frame) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            if (! isset($frame['file'])) {
                continue;
            }

            $frames[] = [
                'file' => $this->relative($frame['file']),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => isset($frame['class']) ? $frame['class'].$frame['type'].$frame['function'].'()' : $frame['function'].'()',
                'app' => $this->isApp($frame['file']),
            ];
        }

        return $frames;
    }

    private function isApp(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        return ! str_contains($file, '/vendor/')
            && ! str_contains($file, '/app/Observability/')
            && str_starts_with($file, str_replace('\\', '/', $this->basePath));
    }

    private function relative(string $file): string
    {
        return ltrim(str_replace([str_replace('\\', '/', $this->basePath), '\\'], ['', '/'], str_replace('\\', '/', $file)), '/');
    }

    /**
     * Strip anything that looks like a credential before it is persisted.
     */
    private function scrub(string $message): string
    {
        return preg_replace('/(password|secret|token|api[_-]?key)(["\']?\s*[:=]\s*)\S+/i', '$1$2[redacted]', $message) ?? $message;
    }
}
