<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * Privacy-safe diagnostics for API validation failures.
 *
 * The log records a correlation id, HTTP status, route, and field names.
 * It does not record messages, headers, files, or submitted values.
 */
class ApiValidationFailure
{
    public const LOG_MESSAGE = 'API validation failed.';

    /**
     * @param  MessageBag|array<string, mixed>  $errors
     */
    public function response(Request $request, MessageBag|array $errors, int $status = 422, ?string $message = null): JsonResponse
    {
        $messages = $errors instanceof MessageBag ? $errors->messages() : $errors;
        $correlationId = (string) Str::uuid();

        Log::warning(self::LOG_MESSAGE, [
            'correlation_id' => $correlationId,
            'status' => $status,
            'fields' => $this->fieldNames($messages),
            'method' => $request->getMethod(),
            'route' => $request->route()?->uri() ?? 'unrouted',
        ]);

        return response()->json([
            'message' => $message ?? 'Validation failed',
            'errors' => $messages,
            'correlation_id' => $correlationId,
        ], $status);
    }

    /**
     * @param  array<mixed, mixed>  $errors
     * @return list<string>
     */
    public function fieldNames(array $errors): array
    {
        $names = [];
        foreach (array_keys($errors) as $field) {
            $name = is_string($field) || is_int($field) ? (string) $field : '';
            $names[] = self::isSafeFieldName($name) ? $name : 'redacted_field';
        }

        return array_values(array_unique($names));
    }

    public static function isSafeFieldName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 80
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*\z/', $name) === 1;
    }
}
