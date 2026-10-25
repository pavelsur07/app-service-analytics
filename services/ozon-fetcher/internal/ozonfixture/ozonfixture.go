// Package ozonfixture — побайтовые ответы кабинета Ozon из фикстур Conwix
// (api/tests/Fixtures/Marketplace/ozon, CLAUDE.md §9) для тестов разбора.
// Копий в сервисе нет: путь — от каталога пакета internal/<пакет> к корню
// репозитория; в контейнере тулчейна каталог фикстур смонтирован по тому же
// относительному пути (docker-compose.yml, ozon-fetcher-dev).
package ozonfixture

import (
	"os"
	"path/filepath"
	"testing"
)

// Dir — каталог фикстур относительно internal/<пакет>.
const Dir = "../../../../api/tests/Fixtures/Marketplace/ozon"

// Read — фикстура name (путь внутри Dir). Отсутствие — падение теста, не
// пропуск: разбор на боевых ответах — обязательное покрытие.
func Read(t testing.TB, name string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(Dir, filepath.FromSlash(name)))
	if err != nil {
		t.Fatalf("ozonfixture: %v", err)
	}
	return b
}
