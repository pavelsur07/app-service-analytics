package ozonperf

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"slices"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

func TestNoHiddenReplayAfterLostGETResponse(t *testing.T) {
	var incoming, campaign atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		incoming.Add(1)
		if r.URL.Path == EndpointToken {
			_, _ = w.Write([]byte(`{"access_token":"synthetic-token","expires_in":1800}`))
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
		campaignsOK(w, r, "")
	}))
	defer srv.Close()
	recorder := &testAttempts{}
	c := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now, recorder)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	require.Equal(t, int32(2), recorder.calls.Load())
	require.Equal(t, int32(2), incoming.Load())
	require.Equal(t, int32(1), campaign.Load())
}

func TestSameHostRedirectDoesNotSendUnreservedRequest(t *testing.T) {
	var target atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == EndpointToken {
			_, _ = w.Write([]byte(`{"access_token":"synthetic-token","expires_in":1800}`))
			return
		}
		if r.URL.Path == "/redirected" {
			target.Add(1)
			campaignsOK(w, r, "")
			return
		}
		http.Redirect(w, r, "/redirected", http.StatusFound)
	}))
	defer srv.Close()
	recorder := &testAttempts{}
	c := NewClient(srv.URL, srv.Client(), slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now, recorder)
	_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
	require.Error(t, err)
	require.Zero(t, target.Load())
	require.Equal(t, int32(2), recorder.calls.Load())
}

func TestPerformanceTLSUsesFreshHTTP1ConnectionsWithoutChangingCaller(t *testing.T) {
	var mu sync.Mutex
	peers := map[string]bool{}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.ProtoMajor != 1 {
			t.Errorf("unexpected HTTP version: %s", r.Proto)
		}
		mu.Lock()
		peers[r.RemoteAddr] = true
		mu.Unlock()
		if r.URL.Path == EndpointToken {
			_, _ = w.Write([]byte(`{"access_token":"synthetic-tls","expires_in":1800}`))
			return
		}
		campaignsOK(w, r, "")
	}))
	server.EnableHTTP2 = true
	server.StartTLS()
	defer server.Close()
	caller := server.Client()
	original := caller.Transport.(*http.Transport)
	original.TLSClientConfig.NextProtos = []string{"h2", "http/1.1"}
	keepAlivesDisabled, http2 := original.DisableKeepAlives, original.ForceAttemptHTTP2
	alpn := slices.Clone(original.TLSClientConfig.NextProtos)
	recorder := &testAttempts{}
	c := NewClient(server.URL, caller, slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now, recorder)
	for range 2 {
		_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
		require.NoError(t, err)
	}
	mu.Lock()
	connections := len(peers)
	mu.Unlock()
	require.Equal(t, 3, connections)
	require.Equal(t, int32(3), recorder.calls.Load())
	require.Same(t, original, caller.Transport)
	require.Equal(t, keepAlivesDisabled, original.DisableKeepAlives)
	require.Equal(t, http2, original.ForceAttemptHTTP2)
	require.Equal(t, alpn, original.TLSClientConfig.NextProtos)
}

type opaqueTransport struct{}

func (opaqueTransport) RoundTrip(*http.Request) (*http.Response, error) {
	return nil, errors.New("opaque transport must not be used")
}

func TestOpaqueAndNilPerformanceTransportCannotBypassAccounting(t *testing.T) {
	for _, transport := range []http.RoundTripper{opaqueTransport{}, (*http.Transport)(nil)} {
		recorder := &testAttempts{}
		c := NewClient("https://unused.invalid", &http.Client{Transport: transport}, slog.New(slog.NewJSONHandler(&bytes.Buffer{}, nil)), time.Now, recorder)
		_, err := c.FetchCampaigns(context.Background(), testRef, testCreds)
		require.ErrorContains(t, err, "unsupported performance transport")
		require.Zero(t, recorder.calls.Load())
	}
}
