package config

import "testing"

func TestConfigurationIsObserveOnly(t *testing.T) {
	t.Setenv("ADS_DB_DSN", "postgres://sandbox")
	t.Setenv("ADS_PHP_URL", "http://php/internal")
	t.Setenv("ADS_OUTBOUND_TOKEN", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	t.Setenv("ADS_INBOUND_TOKEN", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	c, err := Load()
	if err != nil || c.Enabled || c.Workers != 4 {
		t.Fatal(c, err)
	}
	t.Setenv("ADS_MODE", "control")
	if _, err = Load(); err == nil {
		t.Fatal("control accepted")
	}
	t.Setenv("ADS_MODE", "observe")
	t.Setenv("ADS_WORKERS", "0")
	if _, err = Load(); err == nil {
		t.Fatal("zero capacity")
	}
	t.Setenv("ADS_WORKERS", "4")
	t.Setenv("ADS_INBOUND_TOKEN", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	if _, err = Load(); err == nil {
		t.Fatal("same direction tokens")
	}
}
