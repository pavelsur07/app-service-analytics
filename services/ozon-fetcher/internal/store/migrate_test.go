package store

import (
	"context"
	"database/sql"
	"testing"

	"github.com/pressly/goose/v3"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/store/storetest"
)

// Рабочий down (services/AGENTS.md §5): миграции откатываются до пустой
// схемы и накатываются заново.
func TestMigrationsRoundTrip(t *testing.T) {
	dsn := storetest.DSN(t, "migrate")
	ctx := context.Background()
	require.NoError(t, Migrate(ctx, dsn))

	db, err := sql.Open("pgx", dsn)
	require.NoError(t, err)
	defer func() { _ = db.Close() }()
	goose.SetBaseFS(migrationsFS)
	require.NoError(t, goose.SetDialect("postgres"))

	require.NoError(t, goose.DownToContext(ctx, db, "migrations", 0))
	var tables int
	require.NoError(t, db.QueryRowContext(ctx,
		`SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name <> 'goose_db_version'`).Scan(&tables))
	assert.Zero(t, tables, "down удаляет всё, что создал up")

	require.NoError(t, Migrate(ctx, dsn))
}

// Гейт выкладки: на пустой базе и после отката последней миграции —
// неприменённые есть, после Migrate — нет.
func TestMigrationsPendingGate(t *testing.T) {
	dsn := storetest.DSN(t, "migrate_gate")
	ctx := context.Background()

	pending, err := MigrationsPending(ctx, dsn)
	require.NoError(t, err)
	assert.True(t, pending, "пустая база")

	require.NoError(t, Migrate(ctx, dsn))
	pending, err = MigrationsPending(ctx, dsn)
	require.NoError(t, err)
	assert.False(t, pending, "всё применено")

	db, err := sql.Open("pgx", dsn)
	require.NoError(t, err)
	defer func() { _ = db.Close() }()
	goose.SetBaseFS(migrationsFS)
	require.NoError(t, goose.SetDialect("postgres"))
	require.NoError(t, goose.DownContext(ctx, db, "migrations"))
	pending, err = MigrationsPending(ctx, dsn)
	require.NoError(t, err)
	assert.True(t, pending, "последняя миграция откачена")
}
