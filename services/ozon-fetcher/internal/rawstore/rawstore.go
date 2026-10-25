// Package rawstore — неизменяемые raw-объекты в S3 (services/AGENTS.md §9):
// gzip исходных байтов ответа, ключ по содержимому, запрет перезаписи.
// Объекты лежат в бакете сырья Conwix под префиксом сервиса (ADR-044).
package rawstore

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"regexp"
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

// Object — страница ответа источника. Подключение передаётся в Put отдельно.
type Object struct {
	ResourceType string
	PeriodKey    string
	ContentType  string
	Body         []byte
}

// Locator — поля, из которых собирается ключ объекта подключения: ресурс,
// период и содержимое. Готовый ключ порт не принимает (CLAUDE.md §1):
// при чтении он пересобирается из строки raw_object, найденной по подключению.
type Locator struct {
	ResourceType string
	PeriodKey    string
	SHA256       string // от несжатых байтов
	ContentType  string
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

// Put записывает объект подключения ref. Ключ — sha256 содержимого: тот же
// ключ — те же байты, поэтому повтор после сбоя идемпотентен, а
// существующий объект (412 на If-None-Match) — успех, а не перезапись.
func (s *Store) Put(ctx context.Context, ref account.Ref, obj Object) (Stored, error) {
	sum := sha256.Sum256(obj.Body)
	digest := hex.EncodeToString(sum[:])
	key, err := Key(ref, Locator{ResourceType: obj.ResourceType, PeriodKey: obj.PeriodKey, SHA256: digest, ContentType: obj.ContentType})
	if err != nil {
		return Stored{}, err
	}

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
			"company-id":             ref.CompanyID,
			"marketplace-account-id": ref.AccountID,
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

// Prefix — пространство сервиса в бакете сырья Conwix (ADR-044). Константа,
// а не настройка: ошибкой конфигурации сервис не попадёт в префикс PHP
// (conwix/raw/v1, ADR-024). Срок хранения и права ключа хранилища задаются
// на этот префикс.
const Prefix = "conwix/svc/ozon-fetcher/v1"

// ErrInvalidKey — сегмент ключа вне допустимой формы: собранный из него путь
// мог бы выйти из префикса подключения.
var ErrInvalidKey = errors.New("rawstore: invalid key segment")

var (
	keySegment = regexp.MustCompile(`^[a-z0-9]+([.-][a-z0-9]+)*$`)
	sha256Form = regexp.MustCompile(`^[0-9a-f]{64}$`)
)

// ConnectionPrefix — префикс всех объектов подключения, с завершающим "/".
func ConnectionPrefix(ref account.Ref) string {
	return Prefix + "/companies/" + ref.CompanyID + "/accounts/" + ref.AccountID + "/"
}

// Key — {Prefix}/companies/{company_id}/accounts/{marketplace_account_id}/{resource_type}/{period_key}/{sha256}.{ext}.gz
//
// Сегменты не исправляются, а проверяются: компания и подключение — UUID
// (account.Parse), ресурс и период — латиница в нижнем регистре, цифры, точка
// и дефис между ними, sha256 — 64 hex. Разделителя пути и ".." в ключе
// быть не может, поэтому ключ всегда лежит под ConnectionPrefix(ref).
func Key(ref account.Ref, loc Locator) (string, error) {
	if _, err := account.Parse(ref.CompanyID, ref.AccountID); err != nil {
		return "", fmt.Errorf("%w: %w", ErrInvalidKey, err)
	}
	if !keySegment.MatchString(loc.ResourceType) {
		return "", fmt.Errorf("%w: resource_type %q", ErrInvalidKey, loc.ResourceType)
	}
	if !keySegment.MatchString(loc.PeriodKey) {
		return "", fmt.Errorf("%w: period_key %q", ErrInvalidKey, loc.PeriodKey)
	}
	if !sha256Form.MatchString(loc.SHA256) {
		return "", fmt.Errorf("%w: sha256 %q", ErrInvalidKey, loc.SHA256)
	}
	return ConnectionPrefix(ref) + loc.ResourceType + "/" + loc.PeriodKey + "/" + loc.SHA256 + "." + extension(loc.ContentType) + ".gz", nil
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
