package account

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestParse(t *testing.T) {
	const company, acc = "019a1b2c-0000-7000-8000-000000000001", "019a1b2c-0000-7000-8000-0000000000a1"
	ref, err := Parse(company, acc)
	require.NoError(t, err)
	assert.Equal(t, Ref{CompanyID: company, AccountID: acc}, ref)
	assert.Equal(t, company+"/"+acc, ref.String())

	for _, tc := range []struct{ company, account string }{
		{"", acc},
		{company, ""},
		{"019A1B2C-0000-7000-8000-000000000001", acc},
		{company, "../" + acc},
		{company + "/x", acc},
		{"not-a-uuid", acc},
	} {
		_, err := Parse(tc.company, tc.account)
		assert.ErrorIs(t, err, ErrInvalid, "%q/%q", tc.company, tc.account)
	}
}
