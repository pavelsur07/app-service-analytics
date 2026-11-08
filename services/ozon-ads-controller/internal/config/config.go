package config

import (
	"errors"
	"net/url"
	"os"
	"strconv"
)

type Config struct {
	DSN, PHPBase, OutboundToken, InboundToken, Listen string
	Enabled                                           bool
	Workers                                           int
}

func Load() (Config, error) {
	c := Config{DSN: os.Getenv("ADS_DB_DSN"), PHPBase: os.Getenv("ADS_PHP_URL"), OutboundToken: os.Getenv("ADS_OUTBOUND_TOKEN"), InboundToken: os.Getenv("ADS_INBOUND_TOKEN"), Listen: os.Getenv("ADS_LISTEN"), Enabled: os.Getenv("ADS_ENABLED") == "true", Workers: 4}
	if c.Listen == "" {
		c.Listen = ":8080"
	}
	if raw := os.Getenv("ADS_WORKERS"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > 32 {
			return c, errors.New("invalid_workers")
		}
		c.Workers = n
	}
	u, err := url.Parse(c.PHPBase)
	if c.DSN == "" || err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Scheme != "http" && u.Scheme != "https") || len(c.OutboundToken) < 32 || len(c.InboundToken) < 32 || c.OutboundToken == c.InboundToken {
		return c, errors.New("invalid_configuration")
	}
	if mode := os.Getenv("ADS_MODE"); mode != "" && mode != "observe" {
		return c, errors.New("only_observe_supported")
	}
	return c, nil
}
