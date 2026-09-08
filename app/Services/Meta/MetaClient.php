<?php

declare(strict_types=1);

namespace App\Services\Meta;

use App\Models\AppCredential;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Every call to the Graph API goes through here.
 *
 * Responsibilities, and deliberately no others:
 *   - build versioned URLs from the admin-configured Graph version
 *   - attach the access token and its appsecret_proof
 *   - turn a Graph error envelope into a classified GraphError
 *   - hand back a redacted record of the exchange for publish_logs
 *
 * It knows nothing about posts, targets or platforms. That keeps the one piece
 * of code that touches credentials small enough to audit in a sitting.
 */
class MetaClient
{
    /**
     * Keys that must never reach a log, a database row, or an exception
     * message. publish_logs is the table most likely to be read while
     * debugging, which makes it the worst place for a token to sit.
     */
    private const REDACTED = [
        'access_token',
        'client_secret',
        'appsecret_proof',
        'code',
        'fb_exchange_token',

        /*
         * debug_token takes the token being inspected as `input_token`, and an
         * app token is literally "app_id|app_secret". Without this entry the
         * app secret would be written to publish_logs in plain text.
         *
         * Found by running the real publish path end to end against a stand-in
         * Graph API and reading what actually went over the wire -- not by
         * reading this list.
         */
        'input_token',
    ];

    private const REDACTION = '[redacted]';

    /** @var null|Closure(GraphExchange): void */
    private ?Closure $recorder = null;

    private readonly string $baseUrl;

    public function __construct(
        private readonly AppCredential $credential,
        ?string $baseUrl = null,
    ) {
        $this->baseUrl = $baseUrl
            ?? (string) config('gnext.meta.base_url', 'https://graph.facebook.com');
    }

    /**
     * Attach a callback that receives every exchange, successful or not.
     *
     * @param  null|Closure(GraphExchange): void  $recorder
     */
    public function recordUsing(?Closure $recorder): static
    {
        $this->recorder = $recorder;

        return $this;
    }

    public function graphVersion(): string
    {
        return $this->credential->graphVersion();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('GET', $path, $query, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function post(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('POST', $path, $payload, $token);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    protected function send(string $method, string $path, array $parameters, ?string $token): array
    {
        $url = $this->url($path);
        $parameters = $this->withAuthentication($parameters, $token);

        $startedAt = hrtime(true);

        try {
            $response = $method === 'GET'
                ? $this->http()->get($url, $parameters)
                : $this->http()->asForm()->post($url, $parameters);
        } catch (ConnectionException $exception) {
            $this->record($method, $url, $parameters, [], null, $this->elapsedMs($startedAt));

            throw new GraphException(GraphError::fromTransport($exception->getMessage()));
        }

        $body = $this->decode($response);

        $this->record($method, $url, $parameters, $body, $response->status(), $this->elapsedMs($startedAt));

        if ($response->failed() || isset($body['error'])) {
            throw new GraphException(GraphError::fromResponse($body, $response->status()));
        }

        return $body;
    }

    protected function http()
    {
        return Http::connectTimeout(10)
            ->timeout(60)
            ->acceptJson()
            // Meta answers 4xx with a meaningful error envelope, so we read the
            // body ourselves rather than letting the client throw on status.
            ->withOptions(['http_errors' => false]);
    }

    protected function url(string $path): string
    {
        $path = ltrim($path, '/');

        return rtrim($this->baseUrl, '/').'/'.$this->graphVersion().'/'.$path;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function withAuthentication(array $parameters, ?string $token): array
    {
        if ($token === null) {
            return $parameters;
        }

        $parameters['access_token'] = $token;

        /*
         * appsecret_proof binds the call to this app, so a leaked token alone
         * cannot be replayed against the Graph API from elsewhere. Meta ignores
         * it when the app does not require it, so sending it always is free.
         */
        if (config('gnext.meta.send_appsecret_proof', true)) {
            $secret = $this->credential->meta_app_secret;

            if (filled($secret)) {
                $parameters['appsecret_proof'] = hash_hmac('sha256', $token, (string) $secret);
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        // Meta occasionally answers HTML (a gateway page, a block screen). That
        // is not a Graph error envelope, so say so plainly rather than
        // pretending we parsed something.
        return [
            'error' => [
                'message' => 'Meta returned a non-JSON response (HTTP '.$response->status().').',
                'type' => 'non_json',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $body
     */
    protected function record(string $method, string $url, array $parameters, array $body, ?int $status, int $durationMs): void
    {
        if ($this->recorder === null) {
            return;
        }

        ($this->recorder)(new GraphExchange(
            method: $method,
            endpoint: $url,
            requestPayload: $this->redact($parameters),
            responseBody: $body,
            httpCode: $status,
            durationMs: $durationMs,
        ));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function redact(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $parameters[$key] = self::REDACTION;

                continue;
            }

            if (is_array($value)) {
                $parameters[$key] = $this->redact($value);
            }
        }

        return $parameters;
    }

    private function elapsedMs(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
