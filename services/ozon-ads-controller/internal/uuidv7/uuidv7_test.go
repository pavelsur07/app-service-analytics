package uuidv7

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestNewIsVersion7WithTimestamp(t *testing.T) {
	at := time.Date(2026, 10, 24, 12, 0, 0, 123_000_000, time.UTC)
	seen := map[string]bool{}
	for range 100 {
		id := New(at)
		assert.Regexp(t, `^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`, id)
		// 2026-10-24T12:00:00.123Z = 1792843200123 мс = 0x01a16dc8367b.
		assert.Equal(t, "01a16dc8-367b", id[:13])
		assert.False(t, seen[id])
		seen[id] = true
	}
}

func TestNewOrdersByTime(t *testing.T) {
	at := time.Date(2026, 10, 24, 12, 0, 0, 0, time.UTC)
	assert.Less(t, New(at), New(at.Add(time.Millisecond)))
	assert.Less(t, New(at), New(at.Add(24*time.Hour)))
}
