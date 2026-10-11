<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Exception\RequestExceptionInterface;
use Arix\HttpFoundation\Response;
use Closure;
use Throwable;

class ErrorResponder
{
    public function __construct(
        private readonly bool $debug = false,
        private readonly Closure|null $logger = null
    ) {}

    public function respond(Throwable $exception): Response
    {
        $headers = [];

        if ($exception instanceof HttpException) {
            $status = HttpStatusCode::from($exception->status);
            $message = $exception->getMessage();
            $headers = $exception->headers;
        } elseif ($exception instanceof RequestExceptionInterface) {
            $status = HttpStatusCode::BAD_REQUEST;
            $message = 'Bad request.';
        } else {
            $status = HttpStatusCode::INTERNAL_SERVER_ERROR;
            $message = $this->debug ? (string) $exception : 'Internal server error.';
            $log = "Arix: {$exception}";

            try {
                if ($this->logger !== null) {
                    ($this->logger)($log);
                } else {
                    error_log($log);
                }
            } catch (Throwable $loggingException) {
                try {
                    @error_log("Arix logger failed: {$loggingException}\n{$log}");
                } catch (Throwable) {
                    // Logging is best-effort and must never prevent an HTTP error response.
                }
            }
        }

        $response = new Response($message, $status->value, $headers);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
