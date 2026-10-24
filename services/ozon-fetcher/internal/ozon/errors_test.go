package ozon

import (
	"net/http"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

var testNow = time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)

func TestClassify(t *testing.T) {
	tests := []struct {
		name      string
		status    int
		header    http.Header
		body      string
		wantNil   bool
		wantClass Class
		wantCode  int
		wantRetry time.Duration
	}{
		{name: "ok", status: 200, wantNil: true},
		{name: "ok 204", status: 204, wantNil: true},
		{name: "rate limit seconds", status: 429, header: http.Header{"Retry-After": {"7"}}, wantClass: ClassRateLimit, wantRetry: 7 * time.Second},
		{name: "rate limit http date", status: 429, header: http.Header{"Retry-After": {testNow.Add(30 * time.Second).Format(http.TimeFormat)}}, wantClass: ClassRateLimit, wantRetry: 30 * time.Second},
		{name: "rate limit without header", status: 429, wantClass: ClassRateLimit},
		{name: "rate limit garbage header", status: 429, header: http.Header{"Retry-After": {"soon"}}, wantClass: ClassRateLimit},
		{name: "rate limit negative", status: 429, header: http.Header{"Retry-After": {"-5"}}, wantClass: ClassRateLimit},
		{name: "rate limit date in past", status: 429, header: http.Header{"Retry-After": {testNow.Add(-time.Minute).Format(http.TimeFormat)}}, wantClass: ClassRateLimit},
		{name: "server error", status: 500, body: `{"code":13,"message":"internal"}`, wantClass: ClassTransient, wantCode: 13},
		{name: "bad gateway html", status: 502, body: `<html>bad gateway</html>`, wantClass: ClassTransient},
		{name: "unauthorized", status: 401, body: `{"code":16,"message":"Client-Id and Api-Key headers are required"}`, wantClass: ClassAuth, wantCode: 16},
		{name: "forbidden", status: 403, wantClass: ClassAuth},
		{name: "obsolete by code", status: 400, body: `{"code":9,"message":"obsolete method cannot be used"}`, wantClass: ClassObsoleteEndpoint, wantCode: 9},
		{name: "obsolete by message", status: 400, body: `{"code":3,"message":"Obsolete method cannot be used"}`, wantClass: ClassObsoleteEndpoint, wantCode: 3},
		{name: "bad request", status: 400, body: `{"code":3,"message":"invalid date"}`, wantClass: ClassInvalidRequest, wantCode: 3},
		{name: "not found", status: 404, wantClass: ClassInvalidRequest},
		{name: "code 9 on non-400 is not obsolete", status: 404, body: `{"code":9}`, wantClass: ClassInvalidRequest, wantCode: 9},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			header := tt.header
			if header == nil {
				header = http.Header{}
			}
			got := Classify("/v1/finance/accrual/by-day", tt.status, header, []byte(tt.body), testNow)
			if tt.wantNil {
				assert.Nil(t, got)
				return
			}
			require.NotNil(t, got)
			assert.Equal(t, tt.wantClass, got.Class)
			assert.Equal(t, tt.status, got.Status)
			assert.Equal(t, tt.wantCode, got.Code)
			assert.Equal(t, tt.wantRetry, got.RetryAfter)
		})
	}
}

func TestClassifyTruncatesMessage(t *testing.T) {
	long := make([]rune, 500)
	for i := range long {
		long[i] = 'я'
	}
	got := Classify("/x", 400, http.Header{}, []byte(`{"code":3,"message":"`+string(long)+`"}`), testNow)
	require.NotNil(t, got)
	assert.Len(t, []rune(got.Message), messageLimit)
}
