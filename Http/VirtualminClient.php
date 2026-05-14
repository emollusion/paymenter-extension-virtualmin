<?php

namespace Paymenter\Extensions\Servers\Virtualmin\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Virtualmin Remote API.
 *
 * All calls target the single master node. Cluster distribution
 * is handled transparently by Virtualmin itself.
 *
 * @see https://www.virtualmin.com/docs/development/remote-api/
 */
class VirtualminClient
{
    // Properties declared before constructor (PHP 8.2 strict compliance)
    private string $baseUrl;
    private string $username;
    private string $password;
    private bool $verifyTls;
    private int $timeout;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        bool $verifyTls = true,
        int $timeout = 180
    ) {
        $this->baseUrl   = "https://{$host}:{$port}/virtual-server/remote.cgi";
        $this->username  = $username;
        $this->password  = $password;
        $this->verifyTls = $verifyTls;
        $this->timeout   = $timeout;
    }

    /**
     * Execute a Virtualmin remote API program.
     *
     * @param  string               $program  Virtualmin CLI program name (e.g. 'create-domain')
     * @param  array<string, mixed> $params   Parameters passed to the program
     * @return array<string, mixed> Parsed JSON response
     *
     * @throws VirtualminApiException
     */
    public function call(string $program, array $params = []): array
    {
        $payload = array_merge(
            ['program' => $program, 'json' => '1'],
            $params
        );

        try {
            $http = Http::withBasicAuth($this->username, $this->password)
                ->timeout($this->timeout)
                ->asForm();

            // withoutVerifying() takes no argument — only call it when needed.
            // BUG FIX: original code used ->withoutVerifying(!$this->verifyTls)
            // which is both inverted and incorrect (the method takes no bool arg).
            if (!$this->verifyTls) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post($this->baseUrl, $payload);

        } catch (ConnectionException $e) {
            // Omit baseUrl from exception message — may be logged to external services.
            Log::warning("Virtualmin: connection failure for program '{$program}'", [
                'error' => $e->getMessage(),
            ]);
            throw new VirtualminApiException(
                "Could not connect to the Virtualmin API for program '{$program}': {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($response->unauthorized()) {
            throw new VirtualminApiException(
                "Virtualmin API authentication failed for program '{$program}'. Check API credentials."
            );
        }

        if (!$response->successful()) {
            throw new VirtualminApiException(
                "Virtualmin API HTTP error {$response->status()} for program '{$program}'"
            );
        }

        $body = $response->json();

        if ($body === null) {
            $text = substr(trim($response->body()), 0, 200);
            throw new VirtualminApiException(
                "Virtualmin API returned non-JSON response for '{$program}'. Body snippet: {$text}"
            );
        }

        $status = $body['status'] ?? 'error';
        $output = $body['output'] ?? '';

        if ($status !== 'success') {
            // Prefer full_error over output — it contains the complete error message
            $errorDetail = $body['full_error'] ?? $body['error'] ?? $output;
            Log::log($program === 'list-domains' ? 'debug' : 'warning', "Virtualmin API failure [{$program}]", [
                'error'      => $body['error'] ?? '',
                'full_error' => $body['full_error'] ?? '',
                'output'     => $output,
            ]);
            throw new VirtualminApiException(
                "Virtualmin program '{$program}' failed: {$errorDetail}"
            );
        }

        // Virtualmin bug: status can be "success" even when output contains a logical error.
        // See: https://github.com/virtualmin/virtualmin-gpl/issues/799
        if ($this->outputIndicatesError($output)) {
            Log::warning("Virtualmin API logical error disguised as success [{$program}]", [
                'output' => $output,
            ]);
            throw new VirtualminApiException(
                "Virtualmin program '{$program}' reported an error: {$output}"
            );
        }

        return $body;
    }

    /**
     * Convenience wrapper for list-* programs requiring multiline output.
     *
     * @return array<string, mixed>
     *
     * @throws VirtualminApiException
     */
    public function list(string $program, array $params = []): array
    {
        return $this->call($program, array_merge($params, ['multiline' => '']));
    }

    /**
     * Detect error strings Virtualmin wraps in a success status.
     * Patterns sourced from Virtualmin source and community issue tracker.
     */
    private function outputIndicatesError(string $output): bool
    {
        $errorPatterns = [
            'already exists',
            'already hosting',
            'failed to',
            'does not exist',
            'cannot be found',
            'Error:',
            'error :',
            'Cannot create',
        ];

        foreach ($errorPatterns as $pattern) {
            if (stripos($output, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
