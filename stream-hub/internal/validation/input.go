package validation

import (
	"regexp"
	"strings"
)

var (
	// UUID v4 regex pattern
	uuidRegex = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)

	// Basic alphanumeric + hyphen + underscore
	safeIDRegex = regexp.MustCompile(`^[a-zA-Z0-9_-]{1,64}$`)

	// Room ID format: alphanumeric, hyphen, underscore, 1-64 chars
	roomIDRegex = regexp.MustCompile(`^[a-zA-Z0-9_-]{1,64}$`)

	// User name: alphanumeric + spaces + common chars, 1-64 chars
	userNameRegex = regexp.MustCompile(`^[a-zA-Z0-9\s\-_.]{1,64}$`)
)

// ValidateRoomID validates room ID format
func ValidateRoomID(roomID string) error {
	if roomID == "" {
		return ErrEmptyRoomID
	}
	if len(roomID) > 64 {
		return ErrRoomIDTooLong
	}
	if !roomIDRegex.MatchString(roomID) {
		return ErrInvalidRoomIDFormat
	}
	return nil
}

// ValidateUserID validates user ID format
func ValidateUserID(userID string) error {
	if userID == "" {
		return ErrEmptyUserID
	}
	if len(userID) > 64 {
		return ErrUserIDTooLong
	}
	// Allow UUID or alphanumeric with hyphen/underscore
	if !uuidRegex.MatchString(userID) && !safeIDRegex.MatchString(userID) {
		return ErrInvalidUserIDFormat
	}
	return nil
}

// ValidateUserName validates user name format
func ValidateUserName(userName string) error {
	if userName == "" {
		return nil // User name is optional
	}
	if len(userName) > 64 {
		return ErrUserNameTooLong
	}
	if !userNameRegex.MatchString(userName) {
		return ErrInvalidUserNameFormat
	}
	return nil
}

// ValidateRole validates role string
func ValidateRole(role string) error {
	validRoles := map[string]bool{
		"host":   true,
		"viewer": true,
		"guest":  true,
	}
	if role == "" {
		return nil // Default is viewer
	}
	if !validRoles[role] {
		return ErrInvalidRole
	}
	return nil
}

// SanitizeString removes potentially dangerous characters
func SanitizeString(input string) string {
	// Remove null bytes and control characters
	input = strings.ReplaceAll(input, "\x00", "")
	// Trim whitespace
	input = strings.TrimSpace(input)
	return input
}