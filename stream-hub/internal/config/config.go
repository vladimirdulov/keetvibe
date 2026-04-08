package config

import (
	"net/url"
	"os"
	"strconv"
	"strings"

	"github.com/joho/godotenv"
)

type Config struct {
	Host           string
	WSPort         int
	SFUPort        int
	RedisURL       string
	PostgresURL    string
	RabbitMQURL    string
	APIURL         string
	LogLevel       string
	STUNURL        string
	TURNURL        string
	ExternalIP     string
	AllowedOrigins []string
	JWT_SECRET     string
}

func Load() *Config {
	godotenv.Load()

	allowedOrigins := getEnv("ALLOWED_ORIGINS", "")

	return &Config{
		Host:           getEnv("HOST", "0.0.0.0"),
		WSPort:         getEnvInt("WS_PORT", 8080),
		SFUPort:        getEnvInt("SFU_PORT", 8000),
		RedisURL:       getEnv("REDIS_URL", "redis://localhost:6379"),
		PostgresURL:    getEnv("POSTGRES_URL", "postgres://localhost:5432/keetvibe"),
		RabbitMQURL:    getEnv("RABBITMQ_URL", "amqp://localhost:5672"),
		APIURL:         getEnv("API_URL", "http://localhost:8080"),
		LogLevel:       getEnv("LOG_LEVEL", "info"),
		STUNURL:        getEnv("STUN_URL", "stun:stun.l.google.com:19302"),
		TURNURL:        getEnv("TURN_URL", ""),
		ExternalIP:     getEnv("EXTERNAL_IP", ""),
		AllowedOrigins: parseOrigins(allowedOrigins),
		JWT_SECRET:     getEnv("JWT_SECRET", ""),
	}
}

func parseOrigins(origins string) []string {
	if origins == "" {
		return []string{}
	}
	var result []string
	for _, o := range strings.Split(origins, ",") {
		o = strings.TrimSpace(o)
		if o != "" {
			result = append(result, o)
		}
	}
	return result
}

func getEnv(key, defaultValue string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return defaultValue
}

func getEnvInt(key string, defaultValue int) int {
	if value := os.Getenv(key); value != "" {
		if intVal, err := strconv.Atoi(value); err == nil {
			return intVal
		}
	}
	return defaultValue
}

func getEnvURL(key, defaultValue string) *url.URL {
	if value := os.Getenv(key); value != "" {
		if u, err := url.Parse(value); err == nil {
			return u
		}
	}
	u, _ := url.Parse(defaultValue)
	return u
}
