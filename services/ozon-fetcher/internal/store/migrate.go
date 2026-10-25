package store

import (
	"context"
	"database/sql"
	"embed"
	"fmt"
	"io/fs"

	"github.com/pressly/goose/v3"

	_ "github.com/jackc/pgx/v5/stdlib"
)

//go:embed migrations/*.sql
var migrationsFS embed.FS

// Migrate накатывает миграции сервисной БД. Каждая миграция — с рабочим down (services/AGENTS.md §5).
func Migrate(ctx context.Context, dsn string) error {
	goose.SetBaseFS(migrationsFS)
	if err := goose.SetDialect("postgres"); err != nil {
		return fmt.Errorf("store: set dialect: %w", err)
	}
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return fmt.Errorf("store: open: %w", err)
	}
	defer func() { _ = db.Close() }()

	if err := goose.UpContext(ctx, db, "migrations"); err != nil {
		return fmt.Errorf("store: migrate: %w", err)
	}
	return nil
}

// MigrationsPending — есть ли неприменённые миграции сервисной БД. Гейт
// выкладки (services/AGENTS.md §5, аналог doctrine:migrations:up-to-date):
// при неприменённых выкладка останавливается до запуска контейнера,
// миграции применяет агент отдельным шагом (-migrate).
func MigrationsPending(ctx context.Context, dsn string) (bool, error) {
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return false, fmt.Errorf("store: open: %w", err)
	}
	defer func() { _ = db.Close() }()
	sources, err := fs.Sub(migrationsFS, "migrations")
	if err != nil {
		return false, fmt.Errorf("store: migrations fs: %w", err)
	}
	provider, err := goose.NewProvider(goose.DialectPostgres, db, sources)
	if err != nil {
		return false, fmt.Errorf("store: migrations provider: %w", err)
	}
	pending, err := provider.HasPending(ctx)
	if err != nil {
		return false, fmt.Errorf("store: migrations pending: %w", err)
	}
	return pending, nil
}
