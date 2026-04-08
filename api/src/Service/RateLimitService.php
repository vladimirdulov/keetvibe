<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimitEnvelope;

class RateLimitService
{
    private const DEFAULT_LIMIT = 60; // requests per window
    private const DEFAULT_WINDOW = 60; // seconds

    public function __construct(
        private readonly RedisService $redisService,
    ) {}

    /**
     * Check rate limit for a user/IP
     * Returns true if allowed, false if rate limited
     */
    public function check(string $key, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): bool
    {
        $current = $this->redisService->incr("ratelimit:{$key}");
        
        // Set expiry on first request
        if ($current === 1) {
            $this->redisService->set("ratelimit:{$key}", (string)$current, $window);
        }
        
        return $current <= $limit;
    }

    /**
     * Get rate limit info for response headers
     */
    public function getRateLimit(string $key, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): array
    {
        $current = (int) ($this->redisService->get("ratelimit:{$key}") ?: 0);
        
        return [
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'reset' => time() + $window,
        ];
    }

    /**
     * Reset rate limit for a key (for testing)
     */
    public function reset(string $key): void
    {
        $this->redisService->del("ratelimit:{$key}");
    }

    /**
     * Get identifier from request (IP or user ID)
     */
    public function getIdentifier(Request $request, ?string $userId = null): string
    {
        // If user is logged in, use user ID
        if ($userId) {
            return "user:{$userId}";
        }
        
        // Otherwise use IP
        $ip = $request->getClientIp();
        return "ip:" . ($ip ?: 'unknown');
    }
}