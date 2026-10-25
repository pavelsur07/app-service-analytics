package httpapi

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"
	"github.com/stretchr/testify/require"
)

const coverageContract = "../../../_contracts/service-coverage/v1/"

// Обе стороны контракта service-coverage v1 (services/AGENTS.md §3):
// эталон, по которому PHP проверяет свой разбор, и ответ обработчика
// проходят одну JSON Schema. Валидатор — только в тестах.
func coverageSchema(t *testing.T) *jsonschema.Schema {
	t.Helper()
	schema, err := jsonschema.NewCompiler().Compile(coverageContract + "schema.json")
	require.NoError(t, err)
	return schema
}

func TestCoverageContractExampleMatchesSchema(t *testing.T) {
	raw, err := os.ReadFile(coverageContract + "example.json")
	require.NoError(t, err)
	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(raw))
	require.NoError(t, err)

	require.NoError(t, coverageSchema(t).Validate(doc))
}

func TestCoverageResponseMatchesSchema(t *testing.T) {
	var call coverageCall
	req := httptest.NewRequestWithContext(context.Background(), http.MethodGet, "/internal/coverage", nil)
	req.Header.Set("Authorization", "Bearer "+token)
	rec := httptest.NewRecorder()
	New(coverageDeps(t, &call)).ServeHTTP(rec, req)
	require.Equal(t, http.StatusOK, rec.Code)

	doc, err := jsonschema.UnmarshalJSON(bytes.NewReader(rec.Body.Bytes()))
	require.NoError(t, err)
	require.NoError(t, coverageSchema(t).Validate(doc))
}
