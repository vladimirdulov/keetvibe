package hub

import (
	"context"
	"encoding/json"
	"sync"
	"time"

	"github.com/keetvibe/stream-hub/internal/config"
	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog"
)

type Hub struct {
	rooms      map[string]*Room
	clients    map[*Client]bool
	register   chan *Client
	unregister chan *Client
	broadcast  chan *Message
	redis      *redis.Client
	cfg        *config.Config
	log        zerolog.Logger
	mu         sync.RWMutex
	ctx        context.Context
	cancel     context.CancelFunc
	done       chan struct{} // Signals shutdown complete
	running    bool
}

type Message struct {
	Type    string          `json:"type"`
	RoomID  string          `json:"room_id"`
	UserID  string          `json:"user_id"`
	Role    string          `json:"role,omitempty"`
	Payload json.RawMessage `json:"payload,omitempty"`
}

func NewHub(cfg *config.Config, log zerolog.Logger) *Hub {
	ctx, cancel := context.WithCancel(context.Background())
	
	rdb := redis.NewClient(&redis.Options{
		Addr:     cfg.RedisURL,
		Password: "",
		DB:       0,
	})

	h := &Hub{
		rooms:      make(map[string]*Room),
		clients:    make(map[*Client]bool),
		register:   make(chan *Client, 256),
		unregister: make(chan *Client, 256),
		broadcast:  make(chan *Message, 1024),
		cfg:        cfg,
		log:        log,
		ctx:        ctx,
		cancel:     cancel,
		redis:      rdb,
		done:       make(chan struct{}),
		running:    true,
	}

	go h.pingRedis()

	return h
}

func (h *Hub) Start() {
	h.log.Info().Msg("Hub starting...")

	for {
		select {
		case <-h.ctx.Done():
			h.shutdown()
			h.log.Info().Msg("Hub stopped")
			close(h.done)
			return

		case client := <-h.register:
			h.mu.Lock()
			h.clients[client] = true

			room := h.getOrCreateRoom(client.RoomID)
			room.AddClient(client)
			client.Room = room
			h.mu.Unlock()

			h.log.Info().
				Str("user_id", client.UserID).
				Str("room_id", client.RoomID).
				Str("role", client.Role).
				Msg("Client registered")

			h.notifyViewerJoined(client)

		case client := <-h.unregister:
			h.mu.Lock()
			if _, ok := h.clients[client]; ok {
				delete(h.clients, client)
				close(client.Send)

				if client.Room != nil {
					client.Room.RemoveClient(client)
					if client.Room.IsEmpty() {
						delete(h.rooms, client.Room.ID)
					}
				}
			}
			h.mu.Unlock()

			h.log.Info().
				Str("user_id", client.UserID).
				Str("room_id", client.RoomID).
				Msg("Client unregistered")

			h.notifyViewerLeft(client)

		case message := <-h.broadcast:
			h.mu.RLock()
			room, ok := h.rooms[message.RoomID]
			if ok {
				data, _ := json.Marshal(message)
				room.Broadcast(data)
			}
			h.mu.RUnlock()
		}
	}
}

func (h *Hub) Stop() {
	h.mu.Lock()
	if !h.running {
		h.mu.Unlock()
		return
	}
	h.running = false
	h.mu.Unlock()

	h.cancel()
	<-h.done // Wait for shutdown to complete
}

// shutdown handles graceful shutdown of all resources
func (h *Hub) shutdown() {
	h.log.Info().Msg("Shutting down Hub...")

	// Close all rooms
	h.mu.Lock()
	for _, room := range h.rooms {
		room.Stop()
	}
	h.rooms = make(map[string]*Room)
	h.mu.Unlock()

	// Close all client connections
	h.mu.Lock()
	for client := range h.clients {
		close(client.Send)
	}
	h.clients = make(map[*Client]bool)
	h.mu.Unlock()

	// Close Redis connection
	if h.redis != nil {
		h.redis.Close()
	}

	// Close channels
	close(h.register)
	close(h.unregister)
	close(h.broadcast)

	h.log.Info().Msg("Hub shutdown complete")
}

func (h *Hub) getOrCreateRoom(roomID string) *Room {
	if room, ok := h.rooms[roomID]; ok {
		return room
	}

	room := NewRoom(roomID, h, h.log)
	h.rooms[roomID] = room

	go room.Start()

	h.log.Info().Str("room_id", roomID).Msg("Room created")

	return room
}

func (h *Hub) Register(client *Client) {
	h.register <- client
}

func (h *Hub) Unregister(client *Client) {
	h.unregister <- client
}

func (h *Hub) BroadcastToRoom(roomID string, msg *Message) {
	h.broadcast <- msg
}

func (h *Hub) GetRoomClientCount(roomID string) int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	if room, ok := h.rooms[roomID]; ok {
		return room.ClientCount()
	}
	return 0
}

func (h *Hub) RemoveRoom(roomID string) {
	h.mu.Lock()
	if room, ok := h.rooms[roomID]; ok {
		room.Stop()
		delete(h.rooms, roomID)
		h.log.Info().Str("room_id", roomID).Msg("Room removed")
	}
	h.mu.Unlock()
}

func (h *Hub) Broadcast(message *Message) {
	h.broadcast <- message
}

func (h *Hub) pingRedis() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-h.ctx.Done():
			return
		case <-ticker.C:
			ctx, cancel := context.WithTimeout(h.ctx, 5*time.Second)
			if err := h.redis.Ping(ctx).Err(); err != nil {
				h.log.Warn().Err(err).Msg("Redis ping failed")
			}
			cancel()
		}
	}
}

func (h *Hub) Redis() *redis.Client {
	return h.redis
}

func (h *Hub) notifyViewerJoined(client *Client) {
	msg := &Message{
		Type:   "viewer_joined",
		RoomID: client.RoomID,
		UserID: client.UserID,
		Role:   client.Role,
	}

	data, _ := json.Marshal(map[string]interface{}{
		"type":    "viewer_joined",
		"room_id": client.RoomID,
		"user_id": client.UserID,
		"user_name": client.UserName,
		"count":   client.Room.ClientCount(),
	})

	h.BroadcastToRoom(client.RoomID, msg)
	_ = data
}

func (h *Hub) notifyViewerLeft(client *Client) {
	msg := &Message{
		Type:   "viewer_left",
		RoomID: client.RoomID,
		UserID: client.UserID,
	}

	h.BroadcastToRoom(client.RoomID, msg)
}
