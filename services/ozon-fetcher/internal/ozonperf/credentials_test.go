package ozonperf

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log/slog"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const secret = "perf-secret-0123"

func TestCredentialsNeverPrintSecret(t *testing.T) {
	c := Credentials{ClientID: "perf-1", ClientSecret: secret}
	for _, s := range []string{fmt.Sprint(c), fmt.Sprintf("%+v", c), fmt.Sprintf("%#v", c), fmt.Sprintf("%v", &c)} {
		assert.NotContains(t, s, secret)
		assert.Contains(t, s, "perf-1")
	}
	raw, err := json.Marshal(struct{ C Credentials }{c})
	require.NoError(t, err)
	assert.NotContains(t, string(raw), secret)

	var buf bytes.Buffer
	slog.New(slog.NewJSONHandler(&buf, nil)).Info("x", slog.Any("creds", c), slog.Any("nested", struct{ C Credentials }{c}))
	assert.NotContains(t, buf.String(), secret)
}
