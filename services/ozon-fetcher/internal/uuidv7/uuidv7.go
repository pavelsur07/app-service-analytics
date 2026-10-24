// Package uuidv7 — UUIDv7 по RFC 9562 (CLAUDE.md §2): 48 бит времени в
// миллисекундах, версия, вариант, остальное — crypto/rand. Свой код вместо
// модуля — решение владельца 24.10.2026 (services/go.md «Внешние зависимости»).
package uuidv7

import (
	"crypto/rand"
	"encoding/binary"
	"fmt"
	"time"
)

// New — UUIDv7 на момент now.
func New(now time.Time) string {
	var b [16]byte
	// crypto/rand.Read не возвращает ошибку (Go 1.24+).
	_, _ = rand.Read(b[6:])
	var ts [8]byte
	binary.BigEndian.PutUint64(ts[:], uint64(now.UnixMilli())) //nolint:gosec // G115: время до 1970 не используется
	// Младшие 48 бит времени.
	copy(b[:6], ts[2:])
	b[6] = b[6]&0x0f | 0x70
	b[8] = b[8]&0x3f | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

// Now — UUIDv7 на текущий момент; генератор по умолчанию для параметров
// newID (services/go.md «Паттерны»: генераторы — параметры).
func Now() string { return New(time.Now()) }
