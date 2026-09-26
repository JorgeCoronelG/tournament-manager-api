<?php

namespace App\Exceptions;

use App\Core\Enum\Message;
use App\Core\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as ResponseHttp;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponse;

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            if ($e instanceof CustomErrorException) {
                return $this->errorResponse($e->getMessage(), $e->getCode());
            }

            if ($e instanceof ValidationException) {
                return $this->convertValidationExceptionToResponse($e, $request);
            }

            if ($e instanceof AuthenticationException) {
                return $this->unauthenticated($request, $e);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return $this->errorResponse(Message::AUTHORIZATION_EXCEPTION, Response::HTTP_FORBIDDEN);
            }

            if ($e instanceof NotFoundHttpException) {
                if (! is_null($e->getPrevious()) && $e->getPrevious() instanceof ModelNotFoundException) {
                    return $this->errorResponse(Message::MODEL_NOT_FOUND_EXCEPTION, Response::HTTP_NOT_FOUND);
                }

                return $this->errorResponse(Message::NOT_FOUND_HTTP_EXCEPTION, Response::HTTP_NOT_FOUND);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return $this->errorResponse(Message::METHOD_NOT_ALLOWED_HTTP_EXCEPTION, Response::HTTP_METHOD_NOT_ALLOWED);
            }

            if ($e instanceof ThrottleRequestsException) {
                return $this->errorResponse(Message::THROTTLE_REQUESTS_EXCEPTION, Response::HTTP_TOO_MANY_REQUESTS)
                    ->withHeaders($e->getHeaders());
            }

            if ($e instanceof QueryException) {
                if ($e->errorInfo == null) {
                    return $this->errorResponse(Message::INTERNAL_SERVER_ERROR, Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $codigo = $e->errorInfo[1];
                if ($codigo == 1451) {
                    return $this->errorResponse(Message::QUERY_EXCEPTION_1451, Response::HTTP_CONFLICT);
                }
            }

            if ($e instanceof HttpException) {
                return $this->errorResponse($e->getMessage(), $e->getStatusCode());
            }

            if (config('app.debug')) {
                return $this->errorResponse($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->errorResponse(Message::INTERNAL_SERVER_ERROR, Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    }

    /**
     * Convert an authentication exception into a response.
     *
     * @param  Request  $request
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|ResponseHttp
    {
        return $this->errorResponse(Message::AUTHENTICATION_EXCEPTION, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Create a response object from the given validation exception.
     *
     * @param  Request  $request
     */
    protected function convertValidationExceptionToResponse(ValidationException $e, $request): JsonResponse|ResponseHttp
    {
        $errors = $e->validator->errors()->toArray();

        return $this->errorResponse($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
