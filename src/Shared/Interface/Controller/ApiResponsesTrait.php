<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\CursorPaginatedResponse;
use App\Shared\Interface\DTO\PaginatedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

trait ApiResponsesTrait
{
    protected function json(mixed $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    protected function noContent(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    protected function created(mixed $data = null, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, Response::HTTP_CREATED, $headers);
    }

    protected function successResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    protected function paginatedResponse(PaginatedResponse $paginated): JsonResponse
    {
        return new JsonResponse($paginated->toArray());
    }

    protected function cursorPaginatedResponse(CursorPaginatedResponse $paginated): JsonResponse
    {
        return new JsonResponse($paginated->toArray());
    }

    protected function errorResponse(string $message, int $status = Response::HTTP_BAD_REQUEST, array $details = []): JsonResponse
    {
        $error = new ApiError($message, $status, $details);

        return new JsonResponse($error->toArray(), $status);
    }

    protected function validationErrorResponse(ConstraintViolationListInterface $violations): JsonResponse
    {
        $details = [];
        foreach ($violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $details[$propertyPath][] = $violation->getMessage();
        }

        return $this->errorResponse(
            message: 'Validation failed.',
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            details: $details,
        );
    }

    protected function notFound(?string $message = null): JsonResponse
    {
        return $this->errorResponse(
            $message ?? 'Resource not found.',
            Response::HTTP_NOT_FOUND,
        );
    }

    protected function unauthorized(?string $message = null): JsonResponse
    {
        return $this->errorResponse(
            $message ?? 'Authentication required.',
            Response::HTTP_UNAUTHORIZED,
        );
    }

    protected function forbidden(?string $message = null): JsonResponse
    {
        return $this->errorResponse(
            $message ?? 'Forbidden.',
            Response::HTTP_FORBIDDEN,
        );
    }

    protected function redirect(string $url, int $status = Response::HTTP_FOUND): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }
}
