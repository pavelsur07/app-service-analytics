package control

import (
	"errors"
	"sync"
	"time"
)

var ErrClockSkew = errors.New("clock_skew")

// A wall step must not bypass persisted polling/quota/grant deadlines. Recovery
// requires correcting the host clock and restarting; it never starts advertising.
type clockGate struct {
	mu        sync.Mutex
	wall      time.Time
	monotonic time.Time
	blocked   bool
}

func newClockGate(wall, monotonic time.Time) *clockGate {
	return &clockGate{wall: wall.Round(0), monotonic: monotonic}
}
func (g *clockGate) check(wall, monotonic time.Time) bool {
	g.mu.Lock()
	defer g.mu.Unlock()
	drift := wall.Round(0).Sub(g.wall) - monotonic.Sub(g.monotonic)
	if drift > 5*time.Second || drift < -5*time.Second {
		g.blocked = true
	}
	return !g.blocked
}
