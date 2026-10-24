package connections

import (
	"os"
	"testing"

	"github.com/santhosh-tekuri/jsonschema/v6"
	"github.com/stretchr/testify/require"
)

// Эталонная страница проходит JSON Schema контракта: разбор в
// connections_test.go идёт по ответу, который контракт допускает
// (services/AGENTS.md §3). Валидатор — только в тестах: в бинарник он не
// попадает, разбор в работе строгий сам по себе (ErrInvalidSnapshot).
func TestContractExampleMatchesSchema(t *testing.T) {
	schema, err := jsonschema.NewCompiler().Compile("../../../_contracts/marketplace-connections/v1/schema.json")
	require.NoError(t, err)

	f, err := os.Open(examplePath)
	require.NoError(t, err)
	defer func() { _ = f.Close() }()
	doc, err := jsonschema.UnmarshalJSON(f)
	require.NoError(t, err)

	require.NoError(t, schema.Validate(doc))
}
