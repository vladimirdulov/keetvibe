package hub

import (
	"context"
	"encoding/json"
	"sync"
	"time"

	"github.com/rs/zerolog"
)

type Room struct {
	ID       string
	clients  map[*Client]bool
	mu       sync.RWMutex
	hub      *Hub
	log      zerolog.Logger
	state    *RoomState
	ctx      struct {
		cancel context.CancelFunc
	}
}

type RoomState struct {
	CurrentSlide  int               `json:"current_slide"`
	HostID       string            `json:"host_id"`
	StartedAt    *time.Time        `json:"started_at,omitempty"`
	ViewerCount  int               `json:"viewer_count"`
	HandRaised   map[string]string `json:"hand_raised"`
	RecordingURL string            `json:"recording_url,omitempty"`
}

func NewRoom(id string, hub *Hub, log zerolog.Logger) *Room {
	ctx, cancel := context.WithCancel(context.Background())

	room := &Room{
		ID:      id,
		clients: make(map[*Client]bool),
		hub:     hub,
		log:     log.With().Str("room_id", id).Logger(),
		state: &RoomState{
			HandRaised: make(map[string]string),
		},
	}
	room.ctx.cancel = cancel

	// Start cleanup goroutine - remove room when empty
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case <-time.After(5 * time.Minute):
				if room.IsEmpty() {
					room.log.Info().Msg("Room empty, scheduling cleanup")
					hub.RemoveRoom(room.ID)
					return
				}
			}
		}
	}()

	return room
}

func (r *Room) Start() {
	r.log.Info().Msg("Room started")
}

func (r *Room) Stop() {
	r.ctx.cancel()
	r.log.Info().Msg("Room stopped")
}

func (r *Room) AddClient(client *Client) {
	r.mu.Lock()
	r.clients[client] = true
	r.state.ViewerCount = len(r.clients)
	r.mu.Unlock()

	client.Send <- []byte(`{"type":"room_state","payload":` + r.GetStateJSON() + `}`)
}

func (r *Room) RemoveClient(client *Client) {
	r.mu.Lock()
	delete(r.clients, client)
	r.state.ViewerCount = len(r.clients)

	if client.Role == "host" {
		r.state.HostID = ""
	}
	delete(r.state.HandRaised, client.UserID)
	r.mu.Unlock()
}

func (r *Room) Broadcast(data []byte) {
	r.mu.RLock()
	// Copy clients to a slice to avoid modifying map while iterating
	clients := make([]*Client, 0, len(r.clients))
	for client := range r.clients {
		clients = append(clients, client)
	}
	r.mu.RUnlock()

	// Now iterate over the copy without holding the lock
	for _, client := range clients {
		select {
		case client.Send <- data:
		default:
			// Channel full or closed - remove client
			r.hub.Unregister(client)
		}
	}
}

func (r *Room) IsEmpty() bool {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return len(r.clients) == 0
}

func (r *Room) ClientCount() int {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return len(r.clients)
}

func (r *Room) GetState() *RoomState {
	r.mu.RLock()
	defer r.mu.RUnlock()
	return r.state
}

func (r *Room) GetStateJSON() string {
	r.mu.RLock()
	defer r.mu.RUnlock()

	data, _ := json.Marshal(r.state)
	return string(data)
}

func (r *Room) SetHost(hostID string) {
	r.mu.Lock()
	r.state.HostID = hostID
	r.mu.Unlock()
}

func (r *Room) SetCurrentSlide(slide int) {
	r.mu.Lock()
	r.state.CurrentSlide = slide
	r.mu.Unlock()
}

func (r *Room) RaiseHand(userID, userName string) {
	r.mu.Lock()
	r.state.HandRaised[userID] = userName
	r.mu.Unlock()
}

func (r *Room) LowerHand(userID string) {
	r.mu.Lock()
	delete(r.state.HandRaised, userID)
	r.mu.Unlock()
}

func (r *Room) BroadcastMessage(msg *Message) {
	data, _ := json.Marshal(msg)
	r.Broadcast(data)
}
