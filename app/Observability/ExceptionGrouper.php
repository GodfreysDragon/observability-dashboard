<?php

namespace App\Observability;

use Throwable;

/**
 * Groups exceptions by where they come from rather than what they say: class plus the
 * first application frame. Messages often embed ids or values ("No query results for
 * model [Invoice] 4182"), so grouping on them would split one bug into thousands.
 *
 * The frame is identified by its file and enclosing function, not its line number, so
 * an unrelated edit higher up the file doesn't turn the same bug into a "new" group on
 * the next deploy.
 */
final class ExceptionGrouper
{
    private const int MAX_FRAMES = 25;

    public function __construct(private readonly string $basePath) {}

    public static function fingerprint(string $class, string $file, string $function): string
    {
        return sha1($class.'|'.$file.'|'.$function);
    }

    /**
     * @return array{fingerprint: string, class: string, message: string, file: string, line: int, trace: list<array{file: string, line: int, call: string, app: bool}>}
     */
    public function describe(Throwable $exception): array
    {
        $frames = $this->frames($exception);
        $origin = collect($frames)->first(fn (array $frame) => $frame['app']) ?? $frames[0];
        $class = $exception::class;

        return [
            'fingerprint' => self::fingerprint($class, $origin['file'], $origin['function']),
            'class' => $class,
            'message' => mb_substr($this->scrub($exception->getMessage()), 0, 2000),
            'file' => $origin['file'],
            'line' => $origin['line'],
            'trace' => array_map(fn (array $frame) => array_diff_key($frame, ['function' => true]), $frames),
        ];
    }

    /**
     * Each frame is a file:line plus the function that contains it. PHP's trace entry i
     * names the function *called* at file:line, so the containing function is entry i+1.
     *
     * @return list<array{file: string, line: int, call: string, app: bool, function: string}>
     */
    private function frames(Throwable $exception): array
    {
        $trace = $exception->getTrace();

        $frames = [[
            'file' => $this->relative($exception->getFile()),
            'line' => $exception->getLine(),
            'call' => 'throw '.class_basename($exception),
            'app' => $this->isApp($exception->getFile()),
            'function' => $this->throwSiteFunction($exception, $trace),
        ]];

        foreach ($trace as $i => $frame) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            if (! isset($frame['file'])) {
                continue;
            }

            $frames[] = [
                'file' => $this->relative($frame['file']),
                'line' => (int) ($frame['line'] ?? 0),
                'call' => $this->callName($frame).'()',
                'app' => $this->isApp($frame['file']),
                'function' => isset($trace[$i + 1]) ? $this->callName($trace[$i + 1]) : '{main}',
            ];
        }

        return $frames;
    }

    /**
     * The function containing the throw site. For a converted PHP warning (ErrorException)
     * the trace starts inside the error handler, so look for the entry the engine raised
     * the error from and take the function above it.
     *
     * @param  list<array<string, mixed>>  $trace
     */
    private function throwSiteFunction(Throwable $exception, array $trace): string
    {
        foreach ($trace as $i => $frame) {
            if (($frame['file'] ?? null) === $exception->getFile() && ($frame['line'] ?? null) === $exception->getLine()) {
                return isset($trace[$i + 1]) ? $this->callName($trace[$i + 1]) : '{main}';
            }
        }

        return isset($trace[0]) ? $this->callName($trace[0]) : '{main}';
    }

    /**
     * PHP 8.4 names closures after their file and line ("{closure:Foo::bar():27}"), which
     * would bring line numbers back into the fingerprint.
     *
     * @param  array<string, mixed>  $frame
     */
    private function callName(array $frame): string
    {
        $function = str_starts_with($frame['function'], '{closure') ? '{closure}' : $frame['function'];

        return isset($frame['class']) ? $frame['class'].$frame['type'].$function : $function;
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
