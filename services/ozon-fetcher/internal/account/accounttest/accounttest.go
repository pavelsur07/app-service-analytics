// Package accounttest — подключения для тестов: короткая метка → пара UUID.
// Разные метки дают разные компании, одна метка — всегда одну и ту же пару.
package accounttest

import (
	"crypto/sha256"
	"encoding/hex"
	"sync"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

var labels sync.Map // account.Ref → метка

// Ref — подключение метки label в собственной компании.
func Ref(label string) account.Ref {
	ref := account.Ref{CompanyID: id("company:" + label), AccountID: id("account:" + label)}
	labels.Store(ref, label)
	return ref
}

// Label — метка, из которой получено подключение; "" — не из Ref.
func Label(ref account.Ref) string {
	if l, ok := labels.Load(ref); ok {
		return l.(string) //nolint:forcetypeassert // в карту кладёт только Ref
	}
	return ""
}

// Refs — подключения меток по порядку.
func Refs(names ...string) []account.Ref {
	out := make([]account.Ref, 0, len(names))
	for _, l := range names {
		out = append(out, Ref(l))
	}
	return out
}

// InCompany — второе подключение той же компании, что у Ref(company).
func InCompany(company, label string) account.Ref {
	return account.Ref{CompanyID: Ref(company).CompanyID, AccountID: id("account:" + label)}
}

// id — UUID версии 7 по форме: тесты сверяют его с колонкой uuid.
func id(seed string) string {
	h := sha256.Sum256([]byte(seed))
	s := hex.EncodeToString(h[:16])
	return s[0:8] + "-" + s[8:12] + "-7" + s[13:16] + "-8" + s[17:20] + "-" + s[20:32]
}
