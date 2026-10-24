// Package store — доступ к собственной БД сервиса (см. services/AGENTS.md §9).
package store

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5/pgxpool"
)

// Pool — пул соединений сервисной БД.
type Pool = pgxpool.Pool

// MinConns — нижняя граница пула (services/go.md «Хранение»: 2–5).
const MinConns = 5

// PoolSize — пул под workers параллельных задач: у каждой — короткие
// транзакции и продление lease, плюс захват и фоновое освобождение.
func PoolSize(workers int) int32 {
	return int32(max(MinConns, workers+3)) //nolint:gosec // G115: workers ограничен config (1…64)
}

// Open открывает пул; maxConns — PoolSize(MAX_PARALLEL_CONNECTIONS).
func Open(ctx context.Context, dsn string, maxConns int32) (*Pool, error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, fmt.Errorf("store: parse dsn: %w", err)
	}
	cfg.MaxConns = max(maxConns, MinConns)
	cfg.MinConns = 1
	cfg.ConnConfig.RuntimeParams["statement_timeout"] = "5000"
	cfg.ConnConfig.RuntimeParams["lock_timeout"] = "3000"

	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, fmt.Errorf("store: open pool: %w", err)
	}
	if err := pool.Ping(ctx); err != nil {
		pool.Close()
		return nil, fmt.Errorf("store: ping: %w", err)
	}
	return pool, nil
}
