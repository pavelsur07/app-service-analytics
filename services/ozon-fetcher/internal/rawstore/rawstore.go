// Package rawstore — неизменяемые raw-объекты в S3 (services/AGENTS.md §9):
// gzip исходных байтов ответа, ключ по содержимому, запрет перезаписи.
package rawstore

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/smithy-go"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
)

// Putter — часть S3-клиента, которой пользуется Store (фейк в тестах).
type Putter interface {
	PutObject(ctx context.Context, in *s3.PutObjectInput, opts ...func(*s3.Options)) (*s3.PutObjectOutput, error)
}

// BucketChecker — проверка доступности бакета для /ready.
type BucketChecker interface {
	HeadBucket(ctx context.Context, in *s3.HeadBucketInput, opts ...func(*s3.Options)) (*s3.HeadBucketOutput, error)
}

// Ping — бакет доступен (HeadBucket); для клиента без HeadBucket — nil.
func (s *Store) Ping(ctx context.Context) error {
	checker, ok := s.client.(BucketChecker)
	if !ok {
		return nil
	}
	if _, err := checker.HeadBucket(ctx, &s3.HeadBucketInput{Bucket: aws.String(s.bucket)}); err != nil {
		return fmt.Errorf("rawstore: head bucket: %w", err)
	}
	return nil
}

// Object — страница ответа источника.
type Object struct {
	Provider     string // ozon
	Account      account.Ref
	ResourceType string
	PeriodKey    string
	AttemptID    string
	PageNo       int
	FetchedAt    time.Time
	ContentType  string
	Body         []byte
}

// Stored — что записано: для raw_object и envelope (storage_uri, checksum, size_bytes).
type Stored struct {
	URI         string
	Key         string
	SHA256      string // от несжатых байтов
	SizeBytes   int64
	StoredBytes int64
}

// DefaultPutTimeout — предел одного PUT вместе с повторами SDK: зависший S3
// не должен держать задачу и слот диспетчера.
const DefaultPutTimeout = 2 * time.Minute

// Store пишет объекты в один бакет; parallel ограничивает одновременные PUT.
type Store struct {
	client     Putter
	bucket     string
	sem        chan struct{}
	putTimeout time.Duration
}

func New(client Putter, bucket string, parallel int) *Store {
	return &Store{client: client, bucket: bucket, sem: make(chan struct{}, max(parallel, 1)), putTimeout: DefaultPutTimeout}
}

// WithPutTimeout — копия с другим пределом PUT.
func (s *Store) WithPutTimeout(d time.Duration) *Store {
	clone := *s
	clone.putTimeout = d
	return &clone
}

// Put записывает объект. Ключ содержит попытку, страницу и sha256: тот же
// ключ — те же байты, поэтому повтор после сбоя идемпотентен, а
// существующий объект (412 на If-None-Match) — успех, а не перезапись.
func (s *Store) Put(ctx context.Context, obj Object) (Stored, error) {
	sum := sha256.Sum256(obj.Body)
	digest := hex.EncodeToString(sum[:])
	key := Key(obj, digest)

	compressed, err := gzipBytes(obj.Body)
	if err != nil {
		return Stored{}, fmt.Errorf("rawstore: gzip: %w", err)
	}

	select {
	case s.sem <- struct{}{}:
	case <-ctx.Done():
		return Stored{}, ctx.Err()
	}
	defer func() { <-s.sem }()

	putCtx, cancel := context.WithTimeout(ctx, s.putTimeout)
	defer cancel()
	_, err = s.client.PutObject(putCtx, &s3.PutObjectInput{
		Bucket:        aws.String(s.bucket),
		Key:           aws.String(key),
		Body:          bytes.NewReader(compressed),
		ContentLength: aws.Int64(int64(len(compressed))),
		ContentType:   aws.String("application/gzip"),
		IfNoneMatch:   aws.String("*"),
		Metadata: map[string]string{
			"sha256":                 digest,
			"source-type":            obj.ContentType,
			"source-size":            fmt.Sprint(len(obj.Body)),
			"company-id":             obj.Account.CompanyID,
			"marketplace-account-id": obj.Account.AccountID,
		},
	})
	if err != nil && !isAlreadyExists(err) {
		if ctx.Err() != nil {
			return Stored{}, ctx.Err()
		}
		// Таймаут PUT — обычная ошибка хранилища (повтор задачи), не отмена задачи.
		return Stored{}, fmt.Errorf("rawstore: put %s: %w", key, err)
	}

	return Stored{
		URI:         "s3://" + s.bucket + "/" + key,
		Key:         key,
		SHA256:      digest,
		SizeBytes:   int64(len(obj.Body)),
		StoredBytes: int64(len(compressed)),
	}, nil
}

// Key — {provider}/{company_id}/{marketplace_account_id}/{resource_type}/{period_key}/{fetched_at}_{attempt}_p{NNN}_{sha8}.{ext}.gz
func Key(obj Object, digest string) string {
	return fmt.Sprintf("%s/%s/%s/%s/%s/%s_%s_p%03d_%s.%s.gz",
		safe(obj.Provider), safe(obj.Account.CompanyID), safe(obj.Account.AccountID), safe(obj.ResourceType), safe(obj.PeriodKey),
		obj.FetchedAt.UTC().Format("20060102T150405Z"), safe(obj.AttemptID), obj.PageNo, digest[:8], extension(obj.ContentType))
}

func extension(contentType string) string {
	switch ct := strings.ToLower(contentType); {
	case strings.Contains(ct, "json"):
		return "json"
	case strings.Contains(ct, "spreadsheetml"):
		return "xlsx"
	case strings.Contains(ct, "csv"):
		return "csv"
	default:
		return "bin"
	}
}

// safe — сегмент ключа без разделителей пути, управляющих символов и "..".
func safe(s string) string {
	mapped := strings.Map(func(r rune) rune {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', r == '-', r == '_', r == '.':
			return r
		default:
			return '_'
		}
	}, s)
	mapped = strings.ReplaceAll(mapped, "..", "__")
	if mapped == "" || mapped == "." {
		return "_"
	}
	return mapped
}

// gzipBytes — детерминированный gzip: без имени и времени в заголовке.
func gzipBytes(b []byte) ([]byte, error) {
	var buf bytes.Buffer
	zw, err := gzip.NewWriterLevel(&buf, gzip.BestCompression)
	if err != nil {
		return nil, err
	}
	if _, err := zw.Write(b); err != nil {
		return nil, err
	}
	if err := zw.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func isAlreadyExists(err error) bool {
	// Только 412: объект с этим ключом уже есть. 409 ConditionalRequestConflict
	// не гарантирует, что объект сохранён, — это ошибка, задача повторит PUT.
	var apiErr smithy.APIError
	return errors.As(err, &apiErr) && apiErr.ErrorCode() == "PreconditionFailed"
}
