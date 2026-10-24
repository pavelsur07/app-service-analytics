// Package account — идентичность подключения Conwix: компания и кабинет
// маркетплейса (ADR-043 п. 3, services/AGENTS.md §4). Обе части — UUID из
// одной записи выдачи подключений и по отдельности не передаются.
package account

import (
	"errors"
	"fmt"
	"regexp"
)

// Ref — подключение: компания и marketplace_account_id.
type Ref struct {
	CompanyID string
	AccountID string
}

// String — «company/account» для журнала и сообщений об ошибках.
func (r Ref) String() string { return r.CompanyID + "/" + r.AccountID }

// ErrInvalid — часть идентичности не UUID в канонической форме.
var ErrInvalid = errors.New("account: invalid ref")

var canonicalUUID = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// Parse проверяет обе части: UUID в нижнем регистре с дефисами — форма, в
// которой их отдаёт PHP и хранит PostgreSQL.
func Parse(companyID, accountID string) (Ref, error) {
	if !canonicalUUID.MatchString(companyID) {
		return Ref{}, fmt.Errorf("%w: company_id %q", ErrInvalid, companyID)
	}
	if !canonicalUUID.MatchString(accountID) {
		return Ref{}, fmt.Errorf("%w: marketplace_account_id %q", ErrInvalid, accountID)
	}
	return Ref{CompanyID: companyID, AccountID: accountID}, nil
}
