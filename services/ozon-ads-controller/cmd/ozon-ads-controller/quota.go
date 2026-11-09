package main

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/pavelsur07/app-service-analytics/services/ozon-ads-controller/internal/quota"
)

// Offline only: before config, database, PHP credentials or marketplace clients.
func checkQuota(path string, now time.Time, out io.Writer) int {
	root, err := os.OpenRoot(filepath.Dir(path))
	if err != nil {
		return invalidQuota(out)
	}
	defer func() { _ = root.Close() }()
	f, err := root.Open(filepath.Base(path))
	if err != nil {
		return invalidQuota(out)
	}
	defer func() { _ = f.Close() }()
	p, err := quota.Decode(f)
	if err != nil {
		return invalidQuota(out)
	}
	result, err := p.Check(now)
	if err != nil {
		return invalidQuota(out)
	}
	if json.NewEncoder(out).Encode(result) != nil {
		return 1
	}
	if !result.PassportReady {
		return 2
	}
	return 0
}
func invalidQuota(out io.Writer) int {
	_ = json.NewEncoder(out).Encode(map[string]string{"error": "invalid_quota_passport"})
	return 1
}
