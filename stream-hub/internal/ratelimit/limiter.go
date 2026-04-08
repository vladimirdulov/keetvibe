package ratelimit

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog"
)

type Limiter struct {
	redis      *redis.Client
	log        zerolog.Logger
	mu         sync.Mutex
	localCache map[string]*clientLimit
	limits     *Limits
}

type Limits struct {
	MaxConnectionsPerIP int           // Max WebSocket connections per IP
	MaxMessagesPerUser int           // Max messages per user per second
	MaxRoomsPerUser     int           // Max rooms user can join
	CleanupInterval     time.Duration // How often to clean up old entries
}

type clientLimit struct {
	count      int
	lastReset  time.Time
	messages   int
	lastMsg    time.Time
}

type LimiterConfig struct {
	RedisURL string
	Log      zerolog.Logger
	Limits    *Limits
}

func NewLimiter(cfg *LimiterConfig) *Limiter {
	if cfg.Limits == nil {
		cfg.Limits = &Limits{
			MaxConnectionsPerIP: 10,
			MaxMessagesPerUser: 20,
			MaxRoomsPerUser:     5,
			CleanupInterval:     5 * time.Minute,
		}
	}

	rdb := redis.NewClient(&redis.Options{
		Addr: cfg.RedisURL,
	})

	limiter := &Limiter{
		redis:      rdb,
		log:        cfg.Log,
		localCache: make(map[string]*clientLimit),
		limits:     cfg.Limits,
	}

	// Start cleanup goroutine
	go limiter.cleanup()

	return limiter
}

// AllowConnection checks if a new connection from this IP is allowed
func (l *Limiter) AllowConnection(ip string) bool {
	ctx := context.Background()
	key := fmt.Sprintf("ratelimit:conn:%s", ip)

	// Try to increment counter in Redis
	count, err := l.redis.Incr(ctx, key).Result()
	if err != nil {
		l.log.Warn().Err(err).Msg("Failed to check rate limit in Redis, using local")
		// Fallback to local cache
		return l.allowConnectionLocal(ip)
	}

	// Set expiry if first connection
	if count == 1 {
		l.redis.Expire(ctx, key, time.Minute)
	}

	if int(count) > l.limits.MaxConnectionsPerIP {
		l.log.Warn().Str("ip", ip).Int("count", int(count)).Msg("Rate limit exceeded for connections")
		return false
	}

	return true
}

func (l *Limiter) allowConnectionLocal(ip string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := time.Now()
	if client, exists := l.localCache[ip]; exists {
		if now.Sub(client.lastReset) > time.Minute {
			client.count = 0
			client.lastReset = now
		}
		client.count++
		return client.count <= l.limits.MaxConnectionsPerIP
	}

	l.localCache[ip] = &clientLimit{count: 1, lastReset: now}
	return true
}

// AllowMessage checks if a message from this user is allowed
func (l *Limiter) AllowMessage(userID string) bool {
	ctx := context.Background()
	key := fmt.Sprintf("ratelimit:msg:%s", userID)

	count, err := l.redis.Incr(ctx, key).Result()
	if err != nil {
		l.log.Warn().Err(err).Msg("Failed to check message rate limit in Redis")
		return l.allowMessageLocal(userID)
	}

	if count == 1 {
		l.redis.Expire(ctx, key, time.Second)
	}

	if int(count) > l.limits.MaxMessagesPerUser {
		l.log.Warn().Str("user_id", userID).Int("count", int(count)).Msg("Rate limit exceeded for messages")
		return false
	}

	return true
}

func (l *Limiter) allowMessageLocal(userID string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := time.Now()
	if client, exists := l.localCache[userID]; exists {
		if now.Sub(client.lastMsg) > time.Second {
			client.messages = 0
			client.lastMsg = now
		}
		client.messages++
		return client.messages <= l.limits.MaxMessagesPerUser
	}

	l.localCache[userID] = &clientLimit{messages: 1, lastMsg: now}
	return true
}

// AllowRoomJoin checks if user can join another room
func (l *Limiter) AllowRoomJoin(userID string) bool {
	ctx := context.Background()
	key := fmt.Sprintf("ratelimit:rooms:%s", userID)

	count, err := l.redis.Incr(ctx, key).Result()
	if err != nil {
		l.log.Warn().Err(err).Msg("Failed to check room rate limit in Redis")
		return true // Allow if Redis fails
	}

	if count == 1 {
		l.redis.Expire(ctx, key, time.Hour)
	}

	if int(count) > l.limits.MaxRoomsPerUser {
		l.log.Warn().Str("user_id", userID).Int("count", int(count)).Msg("Rate limit exceeded for rooms")
		return false
	}

	return true
}

// Middleware returns a middleware function for HTTP handlers
func (l *Limiter) Middleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ip, _, err := net.SplitHostPort(r.RemoteAddr)
		if err != nil {
			ip = r.RemoteAddr
		}

		if !l.AllowConnection(ip) {
			http.Error(w, "Too many connections", http.StatusTooManyRequests)
			return
		}

		next.ServeHTTP(w, r)
	})
}

func (l *Limiter) cleanup() {
	ticker := time.NewTicker(l.limits.CleanupInterval)
	defer ticker.Stop()

	for range ticker.C {
		l.mu.Lock()
		now := time.Now()
		for ip, client := range l.localCache {
			if now.Sub(client.lastReset) > 10*time.Minute {
				delete(l.localCache, ip)
			}
		}
		l.mu.Unlock()
	}
}

func (l *Limiter) Close() error {
	return l.redis.Close()
}