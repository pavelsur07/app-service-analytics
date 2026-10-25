package rawstore

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/smithy-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
)

type fakePutter struct {
	mu       sync.Mutex
	objects  map[string][]byte
	inputs   []*s3.PutObjectInput
	err      error
	hang     bool
	inFlight atomic.Int32
	maxSeen  atomic.Int32
	delay    time.Duration
}

func (f *fakePutter) PutObject(ctx context.Context, in *s3.PutObjectInput, _ ...func(*s3.Options)) (*s3.PutObjectOutput, error) {
	if f.hang {
		<-ctx.Done()
		return nil, ctx.Err()
	}
	n := f.inFlight.Add(1)
	defer f.inFlight.Add(-1)
	for {
		seen := f.maxSeen.Load()
		if n <= seen || f.maxSeen.CompareAndSwap(seen, n) {
			break
		}
	}
	time.Sleep(f.delay)

	body, _ := io.ReadAll(in.Body)
	f.mu.Lock()
	defer f.mu.Unlock()
	f.inputs = append(f.inputs, in)
	if f.err != nil {
		return nil, f.err
	}
	if f.objects == nil {
		f.objects = map[string][]byte{}
	}
	key := aws.ToString(in.Key)
	if _, exists := f.objects[key]; exists && aws.ToString(in.IfNoneMatch) == "*" {
		return nil, &smithy.GenericAPIError{Code: "PreconditionFailed"}
	}
	f.objects[key] = body
	return &s3.PutObjectOutput{}, nil
}

var testRef = accounttest.Ref("0193-ref")

func page(body string) Object {
	return Object{ResourceType: "ozon.finance.accrual-by-day", PeriodKey: "2026-10-15", ContentType: "application/json", Body: []byte(body)}
}

// locator — поля строки raw_object, по которым читается объект page(body).
func locator(body string) Locator {
	return Locator{ResourceType: "ozon.finance.accrual-by-day", PeriodKey: "2026-10-15", SHA256: sha256Of(body), ContentType: "application/json"}
}

func gunzip(t *testing.T, b []byte) []byte {
	t.Helper()
	zr, err := gzip.NewReader(bytes.NewReader(b))
	require.NoError(t, err)
	out, err := io.ReadAll(zr)
	require.NoError(t, err)
	return out
}

func TestPutStoresGzipOfOriginalBytesUnderContentKey(t *testing.T) {
	body := `{"accruals":[{"accrual_id":"1"}],"last_id":""}`
	sum := sha256.Sum256([]byte(body))
	digest := hex.EncodeToString(sum[:])
	putter := &fakePutter{}
	ref := testRef

	stored, err := New(putter, "conwix-raw", 2).Put(context.Background(), ref, page(body))

	require.NoError(t, err)
	wantKey := "conwix/svc/ozon-fetcher/v1/companies/" + ref.CompanyID + "/accounts/" + ref.AccountID + "/ozon.finance.accrual-by-day/2026-10-15/" + digest + ".json.gz"
	assert.Equal(t, wantKey, stored.Key)
	assert.Equal(t, "s3://conwix-raw/"+wantKey, stored.URI)
	assert.Equal(t, digest, stored.SHA256)
	assert.EqualValues(t, len(body), stored.SizeBytes)
	assert.Equal(t, body, string(gunzip(t, putter.objects[wantKey])))
	assert.EqualValues(t, len(putter.objects[wantKey]), stored.StoredBytes)

	in := putter.inputs[0]
	assert.Equal(t, "*", aws.ToString(in.IfNoneMatch), "перезапись запрещена")
	assert.Equal(t, digest, in.Metadata["sha256"])
	assert.Equal(t, "application/json", in.Metadata["source-type"])
	assert.Equal(t, ref.CompanyID, in.Metadata["company-id"])
	assert.Equal(t, ref.AccountID, in.Metadata["marketplace-account-id"])
}

func TestPutTreatsExistingObjectAsSuccess(t *testing.T) {
	putter := &fakePutter{}
	store := New(putter, "b", 1)

	first, err := store.Put(context.Background(), testRef, page(`{"a":1}`))
	require.NoError(t, err)
	second, err := store.Put(context.Background(), testRef, page(`{"a":1}`))
	require.NoError(t, err)

	assert.Equal(t, first, second)
	assert.Len(t, putter.objects, 1)
}

func TestPutReturnsStorageErrors(t *testing.T) {
	putter := &fakePutter{err: errors.New("connection reset")}

	_, err := New(putter, "b", 1).Put(context.Background(), testRef, page(`{}`))

	require.Error(t, err)
	assert.Contains(t, err.Error(), "connection reset")
}

func TestPutLimitsParallelism(t *testing.T) {
	putter := &fakePutter{delay: 5 * time.Millisecond}
	store := New(putter, "b", 2)
	var wg sync.WaitGroup
	for i := range 8 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			obj := page(`{"n":` + strconv.Itoa(i) + `}`)
			_, err := store.Put(context.Background(), testRef, obj)
			assert.NoError(t, err)
		}()
	}
	wg.Wait()
	assert.LessOrEqual(t, putter.maxSeen.Load(), int32(2))
	assert.Len(t, putter.objects, 8)
}

func TestKeyExtensionFollowsContentType(t *testing.T) {
	loc := locator("x")
	for contentType, want := range map[string]string{
		"application/json": ".json.gz",
		"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": ".xlsx.gz",
		"text/csv": ".csv.gz",
		"":         ".bin.gz",
	} {
		loc.ContentType = contentType
		key, err := Key(testRef, loc)
		require.NoError(t, err)
		assert.True(t, strings.HasSuffix(key, want), key)
	}
}

// Сегмент, из которого путь мог бы выйти из префикса подключения, не
// исправляется, а отклоняется: ключ всегда под ConnectionPrefix(ref).
func TestKeyRejectsSegmentsLeavingConnectionPrefix(t *testing.T) {
	good := locator("x")
	for name, mutate := range map[string]func(*account.Ref, *Locator){
		"company not uuid":      func(r *account.Ref, _ *Locator) { r.CompanyID = "../" + r.CompanyID },
		"account not uuid":      func(r *account.Ref, _ *Locator) { r.AccountID = "x/../../y" },
		"company upper case":    func(r *account.Ref, _ *Locator) { r.CompanyID = strings.ToUpper(r.CompanyID) },
		"resource dot-dot":      func(_ *account.Ref, l *Locator) { l.ResourceType = ".." },
		"resource slash":        func(_ *account.Ref, l *Locator) { l.ResourceType = "ozon/../../other" },
		"resource empty":        func(_ *account.Ref, l *Locator) { l.ResourceType = "" },
		"resource double dot":   func(_ *account.Ref, l *Locator) { l.ResourceType = "ozon..stocks" },
		"period slash":          func(_ *account.Ref, l *Locator) { l.PeriodKey = "2026/10" },
		"period leading dot":    func(_ *account.Ref, l *Locator) { l.PeriodKey = ".hidden" },
		"period space":          func(_ *account.Ref, l *Locator) { l.PeriodKey = "2026 10" },
		"sha256 short":          func(_ *account.Ref, l *Locator) { l.SHA256 = l.SHA256[:8] },
		"sha256 with separator": func(_ *account.Ref, l *Locator) { l.SHA256 = "../" + l.SHA256[3:] },
	} {
		t.Run(name, func(t *testing.T) {
			ref, loc := testRef, good
			mutate(&ref, &loc)
			_, err := Key(ref, loc)
			require.ErrorIs(t, err, ErrInvalidKey)
		})
	}

	key, err := Key(testRef, good)
	require.NoError(t, err)
	assert.True(t, strings.HasPrefix(key, ConnectionPrefix(testRef)))
	assert.True(t, strings.HasPrefix(key, Prefix+"/companies/"+testRef.CompanyID+"/"), "компания — первый сегмент после префикса сервиса")
}

func TestPutRefusesInvalidKeyWithoutWriting(t *testing.T) {
	putter := &fakePutter{}
	obj := page(`{}`)
	obj.PeriodKey = "../2026-10-15"

	_, err := New(putter, "b", 1).Put(context.Background(), testRef, obj)

	require.ErrorIs(t, err, ErrInvalidKey)
	assert.Empty(t, putter.inputs)
}

// Объект одной компании не записывается под префикс другой: ключ собирается
// только из подключения, переданного в Put.
func TestObjectOfOneCompanyIsNotStoredUnderAnother(t *testing.T) {
	putter := &fakePutter{}
	store := New(putter, "b", 1)
	a, b := accounttest.Ref("company-a"), accounttest.Ref("company-b")

	stored, err := store.Put(context.Background(), a, page(`{"a":1}`))
	require.NoError(t, err)

	assert.True(t, strings.HasPrefix(stored.Key, ConnectionPrefix(a)))
	assert.NotContains(t, stored.Key, b.CompanyID)
	assert.NotContains(t, stored.Key, "/companies/"+b.CompanyID+"/")
}

func TestGzipIsDeterministic(t *testing.T) {
	a, err := gzipBytes([]byte("same"))
	require.NoError(t, err)
	b, err := gzipBytes([]byte("same"))
	require.NoError(t, err)
	assert.Equal(t, a, b)
}

// Интеграция с MinIO: TEST_S3_ENDPOINT (+ TEST_S3_BUCKET, ключи). Без переменной — пропуск.
func TestPutAgainstS3(t *testing.T) {
	endpoint := os.Getenv("TEST_S3_ENDPOINT")
	if endpoint == "" {
		t.Skip("TEST_S3_ENDPOINT is not set")
	}
	client := NewS3Client(endpoint, "us-east-1", envOr("TEST_S3_ACCESS_KEY", "test"), envOr("TEST_S3_SECRET_KEY", "test"))
	bucket := os.Getenv("TEST_S3_BUCKET")
	ctx := context.Background()
	_, _ = client.CreateBucket(ctx, &s3.CreateBucketInput{Bucket: aws.String(bucket)})
	store := New(client, bucket, 2)
	// Своё подключение на прогон: ключ по содержимому, повтор прогона не
	// должен встретить объект прошлого.
	ref := accounttest.Ref(time.Now().Format("150405.000000000"))
	obj := page(`{"accruals":[],"last_id":""}`)

	stored, err := store.Put(ctx, ref, obj)
	require.NoError(t, err)
	assert.True(t, strings.HasPrefix(stored.Key, ConnectionPrefix(ref)))
	again, err := store.Put(ctx, ref, obj)
	require.NoError(t, err, "повтор того же объекта — успех")
	assert.Equal(t, stored, again)

	got, err := client.GetObject(ctx, &s3.GetObjectInput{Bucket: aws.String(bucket), Key: aws.String(stored.Key)})
	require.NoError(t, err)
	defer func() { _ = got.Body.Close() }()
	raw, err := io.ReadAll(got.Body)
	require.NoError(t, err)
	assert.Equal(t, string(obj.Body), string(gunzip(t, raw)))
	assert.Equal(t, stored.SHA256, got.Metadata["sha256"])

	reader := NewReader(client, bucket)
	loc := Locator{ResourceType: obj.ResourceType, PeriodKey: obj.PeriodKey, SHA256: stored.SHA256, ContentType: obj.ContentType}
	read, err := reader.Get(ctx, ref, loc)
	require.NoError(t, err, "Reader читает записанное")
	assert.Equal(t, string(obj.Body), string(read))

	_, err = reader.Get(ctx, accounttest.InCompany(time.Now().Format("150405.000000000")+"-other", "x"), loc)
	require.ErrorIs(t, err, ErrObjectNotFound, "под чужим подключением объекта нет")
}

// envOr — локальный S3 (seaweedfs) принимает любые ключи.
func envOr(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}

func TestConditionalConflictIsNotSuccess(t *testing.T) {
	putter := &fakePutter{err: &smithy.GenericAPIError{Code: "ConditionalRequestConflict"}}

	_, err := New(putter, "b", 1).Put(context.Background(), testRef, page(`{}`))

	require.Error(t, err, "409 не доказывает, что объект сохранён")
}

func TestHungPutIsBoundedByTimeout(t *testing.T) {
	store := New(&fakePutter{hang: true}, "b", 1).WithPutTimeout(20 * time.Millisecond)

	started := time.Now()
	_, err := store.Put(context.Background(), testRef, page(`{}`))

	require.Error(t, err)
	assert.ErrorIs(t, err, context.DeadlineExceeded)
	assert.Less(t, time.Since(started), time.Second)
}
