<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($this->clean($request->all()));

        return $next($request);
    }

    private function clean(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $this->cleanString($value);
            } elseif (is_array($value)) {
                $input[$key] = $this->clean($value);
            }
        }

        return $input;
    }

    private function cleanString(string $value): string
    {
        $value = str_replace("\0", '', $value);

        return trim($value);
    }
}
