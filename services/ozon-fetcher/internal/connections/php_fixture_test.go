package connections

import (
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/santhosh-tekuri/jsonschema/v6"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Страницы настоящего ответа PHP: GET /api/internal/marketplace-connections
// песочницы, limit=2 и курсор следующей страницы (этап 5 плана, снято
// 25.10.2026). Подключения заведены командой
// app:identity:seed-ozon-sandbox-company с фиктивными ключами — реальных
// ключей в песочнице нет (CLAUDE.md «Периметр»).
const (
	phpPage1 = "testdata/php-page-1.json"
	phpPage2 = "testdata/php-page-2.json"
	// phpCursor — next_cursor первой страницы.
	phpCursor = "MDFhMTcyMjMtNmE1ZS03ZDE4LTgzODYtODdlZWZlMDU4NDUw"
)

// Ответ, который PHP отдаёт на самом деле, проходит ту же схему, которой
// его проверяет PHP-тест (InternalMarketplaceConnectionsControllerTest):
// контракт сверен с обеих сторон на одних байтах.
func TestPHPResponsePagesMatchSchema(t *testing.T) {
	schema, err := jsonschema.NewCompiler().Compile("../../../_contracts/marketplace-connections/v1/schema.json")
	require.NoError(t, err)
	for _, path := range []string{phpPage1, phpPage2} {
		f, err := os.Open(path) //nolint:gosec // G304: путь из констант теста
		require.NoError(t, err)
		doc, err := jsonschema.UnmarshalJSON(f)
		_ = f.Close()
		require.NoError(t, err)
		assert.NoError(t, schema.Validate(doc), path)
	}
}

// Клиент обходит настоящие страницы PHP по курсору и собирает снимок
// с ключами, версией и ресурсами каждого подключения.
func TestClientFetchesPHPResponseAcrossPages(t *testing.T) {
	page1, err := os.ReadFile(phpPage1)
	require.NoError(t, err)
	page2, err := os.ReadFile(phpPage2)
	require.NoError(t, err)
	var cursors []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "Bearer "+token, r.Header.Get("Authorization"))
		assert.Equal(t, "ozon", r.URL.Query().Get("provider"))
		cursor := r.URL.Query().Get("cursor")
		cursors = append(cursors, cursor)
		w.Header().Set("Content-Type", "application/json")
		switch cursor {
		case "":
			_, _ = w.Write(page1)
		case phpCursor:
			_, _ = w.Write(page2)
		default:
			http.Error(w, "unknown cursor", http.StatusUnprocessableEntity)
		}
	}))
	defer srv.Close()

	cabinets, err := NewClient(srv.URL, token, srv.Client()).Fetch(context.Background())

	require.NoError(t, err)
	assert.Equal(t, []string{"", phpCursor}, cursors)
	require.Len(t, cabinets, 3)
	first := cabinets[0]
	assert.Equal(t, "01a17223-68d8-7223-b10a-82c83f9fb302", first.Account.AccountID)
	assert.Equal(t, "01a17223-68a1-710d-930c-81725af79833", first.Account.CompanyID)
	assert.True(t, first.UpdatedAt.Equal(time.Date(2026, 10, 25, 8, 18, 5, 0, time.UTC)))
	require.NotNil(t, first.Seller)
	assert.Equal(t, "client-e2e-a", first.Seller.ClientID)
	assert.Nil(t, first.Performance)
	assert.False(t, first.HasPerformance)
	assert.Equal(t, []string{"ozon.finance.accrual-by-day", "ozon.orders.fbo", "ozon.products.list", "ozon.products.info"}, first.ResourceTypes)
	assert.Equal(t, "01a17223-6bcd-760d-8d59-572b04926d04", cabinets[2].Account.AccountID, "подключение второй страницы")
}
