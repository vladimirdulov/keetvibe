package hub

import (
	"encoding/json"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/rs/zerolog"
)

type Client struct {
	ID             string
	UserID         string
	UserName       string
	RoomID         string
	Role           string
	Conn           *websocket.Conn
	Send           chan []byte
	Room           *Room
	hub            *Hub
	log            zerolog.Logger
	mu             sync.Mutex
	RateLimitFunc func(userID string) bool
}

func NewClient(conn *websocket.Conn, hub *Hub, log zerolog.Logger) *Client {
	return &Client{
		Conn: conn,
		hub:  hub,
		Send: make(chan []byte, 256),
		log:  log,
	}
}

func (c *Client) ReadPump() {
	defer func() {
		c.hub.Unregister(c)
		c.Conn.Close()
	}()

	c.Conn.SetReadLimit(65536)
	c.Conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.Conn.SetPongHandler(func(string) error {
		c.Conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})

	for {
		_, data, err := c.Conn.ReadMessage()
		if err != nil {
			if websocket.IsUnexpectedCloseError(err, websocket.CloseGoingAway, websocket.CloseAbnormalClosure) {
				c.log.Warn().Err(err).Msg("WebSocket read error")
			}
			break
		}

		c.handleMessage(data)
	}
}

func (c *Client) WritePump() {
	ticker := time.NewTicker(54 * time.Second)
	defer func() {
		ticker.Stop()
		c.Conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.Send:
			c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				c.Conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}

			c.mu.Lock()
			err := c.Conn.WriteMessage(websocket.TextMessage, message)
			c.mu.Unlock()

			if err != nil {
				c.log.Warn().Err(err).Msg("WebSocket write error")
				return
			}

		case <-ticker.C:
			c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.Conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}

func (c *Client) handleMessage(data []byte) {
	var msg Message
	if err := json.Unmarshal(data, &msg); err != nil {
		c.log.Warn().Err(err).Str("data", string(data)).Msg("Failed to parse message")
		return
	}

	msg.UserID = c.UserID

	switch msg.Type {
	case "join":
		c.handleJoin(msg)
	case "leave":
		c.handleLeave()
	case "slide_change":
		c.handleSlideChange(msg)
	case "chat_message":
		c.handleChatMessage(msg)
	case "hand_raise":
		c.handleHandRaise(msg)
	case "hand_lower":
		c.handleHandLower(msg)
	case "whiteboard_draw":
		c.handleWhiteboardDraw(msg)
	case "cursor_move":
		c.handleCursorMove(msg)
	case "ping":
		c.Send <- []byte(`{"type":"pong"}`)
	default:
		c.log.Debug().Str("type", msg.Type).Msg("Unknown message type")
	}
}

func (c *Client) handleJoin(msg Message) {
	c.UserID = msg.UserID
	c.RoomID = msg.RoomID
	c.Role = msg.Role

	if payload, ok := msg.Payload.(map[string]interface{}); ok {
		if name, ok := payload["user_name"].(string); ok {
			c.UserName = name
		}
	}

	if c.Role == "host" && c.Room != nil {
		c.Room.SetHost(c.UserID)
	}

	c.hub.Register(c)

	response := map[string]interface{}{
		"type":    "joined",
		"room_id": c.RoomID,
		"user_id": c.UserID,
		"role":    c.Role,
	}
	data, _ := json.Marshal(response)
	c.Send <- data
}

func (c *Client) handleLeave() {
	c.hub.Unregister(c)
}

func (c *Client) handleSlideChange(msg Message) {
	if c.Role != "host" {
		c.log.Warn().Str("user_id", c.UserID).Msg("Non-host tried to change slide")
		return
	}

	var payload struct {
		Slide int `json:"slide"`
	}
	if err := json.Unmarshal(msg.Payload, &payload); err != nil {
		return
	}

	if c.Room != nil {
		c.Room.SetCurrentSlide(payload.Slide)
		c.Room.BroadcastMessage(&Message{
			Type:   "slide_change",
			RoomID: c.RoomID,
			UserID: c.UserID,
			Payload: json.RawMessage(`{"slide":` + string(rune(payload.Slide+'0')) + `}`),
		})
	}
}

func (c *Client) handleChatMessage(msg Message) {
	// Rate limit check
	if c.RateLimitFunc != nil && !c.RateLimitFunc(c.UserID) {
		c.log.Warn().Str("user_id", c.UserID).Msg("Rate limit exceeded for messages")
		c.Send <- []byte(`{"type":"error","message":"Rate limit exceeded"}`)
		return
	}

	var payload struct {
		Content   string `json:"content"`
		MessageID string `json:"message_id,omitempty"`
	}
	if err := json.Unmarshal(msg.Payload, &payload); err != nil {
		return
	}

	chatMsg := &Message{
		Type:   "chat_message",
		RoomID: c.RoomID,
		UserID: c.UserID,
		Payload: json.RawMessage(json.Marshal(map[string]interface{}{
			"content":     payload.Content,
			"message_id":  payload.MessageID,
			"user_name":   c.UserName,
			"timestamp":   time.Now().Unix(),
		})),
	}

	if c.Room != nil {
		c.Room.BroadcastMessage(chatMsg)
	}
}

func (c *Client) handleHandRaise(msg Message) {
	if c.Room != nil {
		c.Room.RaiseHand(c.UserID, c.UserName)
		c.Room.BroadcastMessage(&Message{
			Type:   "hand_raised",
			RoomID: c.RoomID,
			UserID: c.UserID,
			Payload: json.RawMessage(json.Marshal(map[string]interface{}{
				"user_id":   c.UserID,
				"user_name": c.UserName,
			})),
		})
	}
}

func (c *Client) handleHandLower(msg Message) {
	if c.Room != nil {
		c.Room.LowerHand(c.UserID)
		c.Room.BroadcastMessage(&Message{
			Type:   "hand_lowered",
			RoomID: c.RoomID,
			UserID: c.UserID,
		})
	}
}

func (c *Client) handleWhiteboardDraw(msg Message) {
	if c.Room != nil {
		msg.UserID = c.UserID
		c.Room.BroadcastMessage(&msg)
	}
}

func (c *Client) handleCursorMove(msg Message) {
	if c.Role == "host" && c.Room != nil {
		msg.UserID = c.UserID
		c.Room.BroadcastMessage(&msg)
	}
}

func (c *Client) SendJSON(data interface{}) {
	if jsonData, err := json.Marshal(data); err == nil {
		c.Send <- jsonData
	}
}
