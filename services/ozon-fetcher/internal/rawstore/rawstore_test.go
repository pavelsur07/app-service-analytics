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
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/smithy-go"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

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

func page(body string) Object {
	return Object{
		Provider: "ozon", Account: accounttest.Ref("0193-ref"), ResourceType: "ozon.finance.accrual-by-day", PeriodKey: "2026-10-15",
		AttemptID: "0193-attempt", PageNo: 2, FetchedAt: time.Date(2026, 10, 17, 9, 30, 5, 0, time.FixedZone("MSK", 3*3600)),
		ContentType: "application/json", Body: []byte(body),
	}
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
	ref := accounttest.Ref("0193-ref")

	stored, err := New(putter, "conwix-ozon-fetcher-raw", 2).Put(context.Background(), page(body))

	require.NoError(t, err)
	wantKey := "ozon/" + ref.CompanyID + "/" + ref.AccountID + "/ozon.finance.accrual-by-day/2026-10-15/20261017T063005Z_0193-attempt_p002_" + digest[:8] + ".json.gz"
	assert.Equal(t, wantKey, stored.Key)
	assert.Equal(t, "s3://conwix-ozon-fetcher-raw/"+wantKey, stored.URI)
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

	first, err := store.Put(context.Background(), page(`{"a":1}`))
	require.NoError(t, err)
	second, err := store.Put(context.Background(), page(`{"a":1}`))
	require.NoError(t, err)

	assert.Equal(t, first, second)
	assert.Len(t, putter.objects, 1)
}

func TestPutReturnsStorageErrors(t *testing.T) {
	putter := &fakePutter{err: errors.New("connection reset")}

	_, err := New(putter, "b", 1).Put(context.Background(), page(`{}`))

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
			obj.PageNo = i
			_, err := store.Put(context.Background(), obj)
			assert.NoError(t, err)
		}()
	}
	wg.Wait()
	assert.LessOrEqual(t, putter.maxSeen.Load(), int32(2))
	assert.Len(t, putter.objects, 8)
}

func TestKeySanitizesSegmentsAndExtensions(t *testing.T) {
	obj := page("x")
	obj.Account.AccountID = "../evil/ref"
	obj.ContentType = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
	key := Key(obj, "abcdef0123456789")

	assert.NotContains(t, key, "..")
	assert.Contains(t, key, "/___evil_ref/")

	obj.Account.CompanyID = "."
	assert.Contains(t, Key(obj, "abcdef0123456789"), "ozon/_/")
	assert.Contains(t, key, ".xlsx.gz")

	obj.ContentType = ""
	assert.Contains(t, Key(obj, "abcdef0123456789"), ".bin.gz")
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
	obj := page(`{"accruals":[],"last_id":""}`)
	obj.AttemptID = time.Now().Format("150405.000000000")

	stored, err := store.Put(ctx, obj)
	require.NoError(t, err)
	again, err := store.Put(ctx, obj)
	require.NoError(t, err, "повтор того же объекта — успех")
	assert.Equal(t, stored, again)

	got, err := client.GetObject(ctx, &s3.GetObjectInput{Bucket: aws.String(bucket), Key: aws.String(stored.Key)})
	require.NoError(t, err)
	defer func() { _ = got.Body.Close() }()
	raw, err := io.ReadAll(got.Body)
	require.NoError(t, err)
	assert.Equal(t, string(obj.Body), string(gunzip(t, raw)))
	assert.Equal(t, stored.SHA256, got.Metadata["sha256"])

	read, err := NewReader(client, bucket).Get(ctx, stored.URI, stored.SHA256)
	require.NoError(t, err, "Reader читает записанное")
	assert.Equal(t, string(obj.Body), string(read))
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

	_, err := New(putter, "b", 1).Put(context.Background(), page(`{}`))

	require.Error(t, err, "409 не доказывает, что объект сохранён")
}

func TestHungPutIsBoundedByTimeout(t *testing.T) {
	store := New(&fakePutter{hang: true}, "b", 1).WithPutTimeout(20 * time.Millisecond)

	started := time.Now()
	_, err := store.Put(context.Background(), page(`{}`))

	require.Error(t, err)
	assert.ErrorIs(t, err, context.DeadlineExceeded)
	assert.Less(t, time.Since(started), time.Second)
}
