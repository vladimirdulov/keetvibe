<?php

namespace App\Trait;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Provides UUID validation utilities for controllers
 */
trait UuidValidatorTrait
{
    /**
     * Validate and parse a UUID string
     * @throws HttpException with 400 status if invalid
     */
    protected function validateUuid(string $uuidString, string $fieldName = 'id'): Uuid
    {
        try {
            return Uuid::fromString($uuidString);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(
                Response::HTTP_BAD_REQUEST,
                "Invalid {$fieldName} format. Must be a valid UUID."
            );
        }
    }

    /**
     * Validate UUID with custom error message
     */
    protected function requireValidUuid(string $uuidString, string $fieldName = 'id'): Uuid
    {
        if (!Uuid::isValid($uuidString)) {
            throw new HttpException(
                Response::HTTP_BAD_REQUEST,
                "Invalid {$fieldName} format. Must be a valid UUID."
            );
        }

        return Uuid::fromString($uuidString);
    }

    /**
     * Try to parse UUID, return null if invalid (non-throwing)
     */
    protected function tryParseUuid(string $uuidString): ?Uuid
    {
        try {
            return Uuid::fromString($uuidString);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}