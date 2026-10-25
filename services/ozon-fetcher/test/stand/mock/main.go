// Мок стенда песочницы: выдача подключений PHP
// (/api/internal/marketplace-connections, контракт marketplace-connections v1)
// и Ozon Seller API с детерминированными ответами. Реальных ключей и
// обращений к настоящему Ozon в песочнице нет (CLAUDE.md «Периметр»).
//
// Подключения: cab-ok (обычное), cab-429 (каждый 3-й запрос by-day — 429 с
// Retry-After: 1), cab-401 (ключ отклонён). Счётчики — GET /stats.
//
// Ozon-часть принимает и подключения настоящей выдачи PHP песочницы:
// ключ сверяется по Client-Id вида client-<метка> и Api-Key key-<метка>,
// так что подключение, заведённое в Conwix с такими ключами, грузится
// сквозь мок (сквозной прогон — README сервиса, «Стенд»).
package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
)

var (
	mu    sync.Mutex
	stats = map[string]int{}
)

func count(key string) int {
	mu.Lock()
	defer mu.Unlock()
	stats[key]++
	return stats[key]
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// accounts — UUID подключения и компании по метке.
var accounts = map[string][2]string{
	"cab-ok":  {"0193a5e0-0000-7000-8000-0000000000a1", "0193a5e0-0000-7000-8000-00000000c0a1"},
	"cab-429": {"0193a5e0-0000-7000-8000-0000000000a2", "0193a5e0-0000-7000-8000-00000000c0a2"},
	"cab-401": {"0193a5e0-0000-7000-8000-0000000000a3", "0193a5e0-0000-7000-8000-00000000c0a3"},
}

func cabinet(ref string) map[string]any {
	return map[string]any{
		"marketplace_account_id": accounts[ref][0], "company_id": accounts[ref][1], "provider": "ozon",
		"updated_at":     "2026-10-17T09:00:00+03:00",
		"credentials":    map[string]any{"seller": map[string]string{"client_id": "client-" + ref, "api_key": "key-" + ref}, "performance": nil},
		"resource_types": []string{"ozon.finance.accrual-by-day", "ozon.orders.fbo", "ozon.products.list", "ozon.products.info"},
	}
}

func main() {
	token := os.Getenv("INTERNAL_AUTH_TOKEN")
	mux := http.NewServeMux()

	mux.HandleFunc("GET /api/internal/marketplace-connections", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+token {
			writeJSON(w, 401, map[string]any{"error": map[string]string{"code": "internal_auth_required"}})
			return
		}
		count("snapshot")
		writeJSON(w, 200, map[string]any{
			"schema_version": "1", "generated_at": time.Now().Format(time.RFC3339),
			"connections": []any{cabinet("cab-ok"), cabinet("cab-429"), cabinet("cab-401")},
			"next_cursor": nil,
		})
	})

	ozon := func(handler func(w http.ResponseWriter, r *http.Request, ref string, body map[string]any)) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			ref := strings.TrimPrefix(r.Header.Get("Client-Id"), "client-")
			if r.Header.Get("Api-Key") != "key-"+ref || ref == "cab-401" {
				count(ref + " 401")
				writeJSON(w, 401, map[string]any{"code": 16, "message": "Invalid Api-Key"})
				return
			}
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			handler(w, r, ref, body)
		}
	}

	mux.HandleFunc("POST /v1/finance/accrual/by-day", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, body map[string]any) {
		n := count(ref + " by-day")
		if ref == "cab-429" && n%3 == 0 {
			count(ref + " 429")
			w.Header().Set("Retry-After", "1")
			writeJSON(w, 429, map[string]any{"code": 8, "message": "rate limit"})
			return
		}
		day, _ := body["date"].(string)
		cursor, _ := body["last_id"].(string)
		if cursor == "" {
			writeJSON(w, 200, map[string]any{"accruals": []any{map[string]any{"accrual_id": day + "-1", "accrued_category": "POSTING"}}, "last_id": day + "-c1"})
			return
		}
		writeJSON(w, 200, map[string]any{"accruals": []any{map[string]any{"accrual_id": day + "-2", "accrued_category": "ITEM"}}, "last_id": ""})
	}))
	mux.HandleFunc("POST /v1/finance/accrual/types", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, _ map[string]any) {
		count(ref + " types")
		writeJSON(w, 200, map[string]any{"accrual_types": []any{map[string]any{"id": 1, "name": "Acquiring"}}})
	}))
	mux.HandleFunc("POST /v2/finance/realization", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, body map[string]any) {
		count(ref + " realization")
		writeJSON(w, 200, map[string]any{"result": map[string]any{"header": map[string]any{"month": body["month"]}, "rows": []any{map[string]any{"rowNumber": 1}}}})
	}))
	mux.HandleFunc("POST /v1/finance/mutual-settlement", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, _ map[string]any) {
		count(ref + " mutual-settlement")
		writeJSON(w, 200, map[string]any{"result": map[string]any{"code": "rep-" + ref}})
	}))
	mux.HandleFunc("POST /v1/report/info", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, _ map[string]any) {
		count(ref + " report-info")
		// Отчёт не формируется на стенде: проверяем исход not_ready.
		writeJSON(w, 200, map[string]any{"result": map[string]any{"status": "processing"}})
	}))
	// Первая волна ADR-043: FBO v3 — одна страница с двумя отправлениями,
	// каталог — страница товаров и пустая страница-конец, карточки — по
	// запрошенным product_id.
	mux.HandleFunc("POST /v3/posting/fbo/list", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, _ map[string]any) {
		count(ref + " fbo")
		writeJSON(w, 200, map[string]any{"postings": []any{
			map[string]any{"posting_number": "0000-" + ref + "-1", "status": "delivered"},
			map[string]any{"posting_number": "0000-" + ref + "-2", "status": "delivering"},
		}, "has_next": false, "cursor": ""})
	}))
	mux.HandleFunc("POST /v3/product/list", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, body map[string]any) {
		count(ref + " product-list")
		if lastID, _ := body["last_id"].(string); lastID != "" {
			writeJSON(w, 200, map[string]any{"result": map[string]any{"items": []any{}, "last_id": "", "total": 2}})
			return
		}
		writeJSON(w, 200, map[string]any{"result": map[string]any{"items": []any{
			map[string]any{"product_id": 1001, "offer_id": ref + "-a"},
			map[string]any{"product_id": 1002, "offer_id": ref + "-b"},
		}, "last_id": "p1", "total": 2}})
	}))
	mux.HandleFunc("POST /v3/product/info/list", ozon(func(w http.ResponseWriter, _ *http.Request, ref string, body map[string]any) {
		count(ref + " product-info")
		ids, _ := body["product_id"].([]any)
		items := make([]any, 0, len(ids))
		for _, id := range ids {
			items = append(items, map[string]any{"id": id, "name": "item " + ref})
		}
		writeJSON(w, 200, map[string]any{"items": items})
	}))
	mux.HandleFunc("GET /stats", func(w http.ResponseWriter, _ *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		writeJSON(w, 200, stats)
	})

	addr := ":8090"
	log.Printf("stand mock listening on %s", addr)
	server := &http.Server{Addr: addr, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	if err := server.ListenAndServe(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
