package validation

import "errors"

var (
	ErrEmptyRoomID       = errors.New("room ID cannot be empty")
	ErrRoomIDTooLong     = errors.New("room ID too long (max 64 characters)")
	ErrInvalidRoomIDFormat = errors.New("room ID contains invalid characters")

	ErrEmptyUserID       = errors.New("user ID cannot be empty")
	ErrUserIDTooLong     = errors.New("user ID too long (max 64 characters)")
	ErrInvalidUserIDFormat = errors.New("user ID contains invalid characters")

	ErrUserNameTooLong   = errors.New("user name too long (max 64 characters)")
	ErrInvalidUserNameFormat = errors.New("user name contains invalid characters")

	ErrInvalidRole      = errors.New("invalid role (must be host, viewer, or guest)")
)