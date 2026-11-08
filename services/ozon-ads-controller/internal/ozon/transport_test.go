package ozon

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"slices"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestNetworkFailureDoesNotSendUnreservedRequest(t *testing.T) {
	var incoming, campaign atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		incoming.Add(1)
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/client/token" {
			_, _ = w.Write([]byte(`{"access_token":"local-probe","token_type":"Bearer","expires_in":1800}`))
			return
		}
		if campaign.Add(1) == 1 {
			conn, _, err := w.(http.Hijacker).Hijack()
			if err != nil {
				t.Error(err)
				return
			}
			_ = conn.Close()
			return
		}
		_, _ = w.Write([]byte(`{"list":[]}`))
	}))
	defer server.Close()
	q := &quota{}
	client, err := New(server.Client(), server.URL, q, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	_, err = client.Campaigns(context.Background(), credential("a"))
	if err == nil || err.Error() != "ozon_transport" {
		t.Fatalf("lost response must be surfaced without a hidden resend: %v", err)
	}
	if q.used.Load() != 2 || incoming.Load() != 2 || campaign.Load() != 1 {
		t.Fatalf("reserved=%d received=%d campaign=%d", q.used.Load(), incoming.Load(), campaign.Load())
	}
}

func TestTLSUsesFreshHTTP1ConnectionsWithoutChangingCaller(t *testing.T) {
	var mu sync.Mutex
	peers := map[string]bool{}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.ProtoMajor != 1 {
			t.Errorf("unexpected HTTP version: %s", r.Proto)
		}
		mu.Lock()
		peers[r.RemoteAddr] = true
		mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/api/client/token" {
			_, _ = w.Write([]byte(`{"access_token":"local-tls","token_type":"Bearer","expires_in":1800}`))
			return
		}
		_, _ = w.Write([]byte(`{"list":[]}`))
	}))
	server.EnableHTTP2 = true
	server.StartTLS()
	defer server.Close()
	caller := server.Client()
	original := caller.Transport.(*http.Transport)
	// Explicit caller preferences: Clone may initialize implicit stdlib defaults.
	original.TLSClientConfig.NextProtos = []string{"h2", "http/1.1"}
	keepAlivesDisabled, http2 := original.DisableKeepAlives, original.ForceAttemptHTTP2
	alpn := slices.Clone(original.TLSClientConfig.NextProtos)
	q := &quota{}
	client, err := New(caller, server.URL, q, time.Now)
	if err != nil {
		t.Fatal(err)
	}
	for range 2 {
		if _, err = client.Campaigns(context.Background(), credential("a")); err != nil {
			t.Fatal(err)
		}
	}
	mu.Lock()
	connections := len(peers)
	mu.Unlock()
	if connections != 3 || q.used.Load() != 3 {
		t.Fatalf("connections=%d reservations=%d", connections, q.used.Load())
	}
	if caller.Transport != original || original.DisableKeepAlives != keepAlivesDisabled || original.ForceAttemptHTTP2 != http2 || !slices.Equal(original.TLSClientConfig.NextProtos, alpn) {
		t.Fatal("constructor changed the caller transport")
	}
}

type opaqueTransport struct{}

func (opaqueTransport) RoundTrip(*http.Request) (*http.Response, error) {
	return nil, fmt.Errorf("opaque transport must not be used")
}

func TestOpaqueAndNilTransportCannotBypassQuotaPolicy(t *testing.T) {
	for _, transport := range []http.RoundTripper{opaqueTransport{}, (*http.Transport)(nil)} {
		if _, err := New(&http.Client{Transport: transport}, "https://api-performance.ozon.ru", &quota{}, time.Now); err == nil || err.Error() != "unsupported_ozon_transport" {
			t.Fatalf("unsupported transport accepted: %v", err)
		}
	}
}
