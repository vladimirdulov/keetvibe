package ws

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/gorilla/mux"
	"github.com/gorilla/websocket"
	"github.com/keetvibe/stream-hub/internal/config"
	"github.com/keetvibe/stream-hub/internal/hub"
	"github.com/rs/zerolog"
)

type Server struct {
	server   *http.Server
	hub      *hub.Hub
	cfg      *config.Config
	log      zerolog.Logger
	upgrader websocket.Upgrader
}

func NewServer(h *hub.Hub, cfg *config.Config, log zerolog.Logger) *Server {
	router := mux.NewRouter()
	router.Use(loggingMiddleware(log))

	s := &Server{
		hub: h,
		cfg: cfg,
		log: log,
		upgrader: websocket.Upgrader{
			ReadBufferSize:  4096,
			WriteBufferSize: 4096,
			CheckOrigin: func(r *http.Request) bool {
				origin := r.Header.Get("Origin")
				if origin == "" {
					// No Origin header - same-origin request, allow it
					return true
				}
				// Check against allowed origins from config
				for _, allowed := range cfg.AllowedOrigins {
					if origin == allowed {
						return true
					}
				}
				log.Warn().Str("origin", origin).Msg("Rejected WebSocket connection from disallowed origin")
				return false
			},
		},
	}

	router.HandleFunc("/ws", s.handleWebSocket)
	router.HandleFunc("/health", s.handleHealth)
	router.HandleFunc("/internal/notify", s.handleInternalNotify)

	s.server = &http.Server{
		Addr:         fmt.Sprintf("%s:%d", cfg.Host, cfg.WSPort),
		Handler:      router,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 10 * time.Second,
	}

	return s
}

func (s *Server) Start() error {
	s.log.Info().Str("addr", s.server.Addr).Msg("WebSocket server starting")
	return s.server.ListenAndServe()
}

func (s *Server) Stop() {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	s.server.Shutdown(ctx)
}

func (s *Server) handleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := s.upgrader.Upgrade(w, r, nil)
	if err != nil {
		s.log.Error().Err(err).Msg("WebSocket upgrade failed")
		return
	}

	query := r.URL.Query()
	token := query.Get("token")
	roomID := query.Get("room")
	userID := query.Get("user_id")
	userName := query.Get("user_name")
	role := query.Get("role")

	if role == "" {
		role = "viewer"
	}

	log := s.log.With().
		Str("room_id", roomID).
		Str("user_id", userID).
		Logger()

	client := hub.NewClient(conn, s.hub, log)
	client.UserID = userID
	client.UserName = userName
	client.RoomID = roomID
	client.Role = role

	go client.WritePump()
	go client.ReadPump()

	s.log.Info().
		Str("user_id", userID).
		Str("room_id", roomID).
		Str("role", role).
		Msg("New WebSocket connection")
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]interface{}{
		"status": "ok",
		"time":   time.Now().Unix(),
	})
}

func (s *Server) handleInternalNotify(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var msg hub.Message
	if err := json.NewDecoder(r.Body).Decode(&msg); err != nil {
		http.Error(w, "Bad request", http.StatusBadRequest)
		return
	}

	s.hub.BroadcastToRoom(msg.RoomID, &msg)

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
}

func loggingMiddleware(log zerolog.Logger) mux.MiddlewareFunc {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			start := time.Now()

			wrapper := &responseWriter{ResponseWriter: w, statusCode: http.StatusOK}
			next.ServeHTTP(wrapper, r)

			log.Debug().
				Str("method", r.Method).
				Str("path", r.URL.Path).
				Int("status", wrapper.statusCode).
				Dur("duration", time.Since(start)).
				Msg("HTTP request")
		})
	}
}

type responseWriter struct {
	http.ResponseWriter
	statusCode int
}

func (rw *responseWriter) WriteHeader(code int) {
	rw.statusCode = code
	rw.ResponseWriter.WriteHeader(code)
}