// Package storetest — изолированная схема PostgreSQL на тестовый пакет:
// пакеты с TEST_DB_DSN идут параллельно и не стирают данные друг друга.
package storetest

import (
	"context"
	"net/url"
	"os"
	"testing"

	"github.com/jackc/pgx/v5"
)

// DSN — DSN схемы test_<name> (пересоздаётся) поверх TEST_DB_DSN; без переменной — Skip.
func DSN(t *testing.T, name string) string {
	t.Helper()
	base := os.Getenv("TEST_DB_DSN")
	if base == "" {
		t.Skip("TEST_DB_DSN is not set")
	}
	schema := "test_" + name
	ctx := context.Background()
	conn, err := pgx.Connect(ctx, base)
	if err != nil {
		t.Fatalf("storetest: connect: %v", err)
	}
	defer func() { _ = conn.Close(ctx) }()
	ident := pgx.Identifier{schema}.Sanitize()
	if _, err := conn.Exec(ctx, "DROP SCHEMA IF EXISTS "+ident+" CASCADE; CREATE SCHEMA "+ident); err != nil {
		t.Fatalf("storetest: create schema: %v", err)
	}

	u, err := url.Parse(base)
	if err != nil {
		t.Fatalf("storetest: parse dsn: %v", err)
	}
	q := u.Query()
	q.Set("search_path", schema)
	u.RawQuery = q.Encode()
	return u.String()
}
