package rawstore

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"strings"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/s3/types"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account"
	"github.com/pavelsur07/app-service-analytics/services/ozon-fetcher/internal/account/accounttest"
)

// getterFromPutter — чтение из того, что записал fakePutter.
type getterFromPutter struct{ p *fakePutter }

func (g getterFromPutter) GetObject(_ context.Context, in *s3.GetObjectInput, _ ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	g.p.mu.Lock()
	defer g.p.mu.Unlock()
	body, ok := g.p.objects[aws.ToString(in.Key)]
	if !ok {
		return nil, &types.NoSuchKey{}
	}
	return &s3.GetObjectOutput{Body: io.NopCloser(bytes.NewReader(body))}, nil
}

func TestReaderReturnsOriginalBytesOfStoredObject(t *testing.T) {
	putter := &fakePutter{}
	store := New(putter, "raw", 1)
	body := `{"postings":[{"posting_number":"P-1"}]}`
	_, err := store.Put(context.Background(), testRef, page(body))
	require.NoError(t, err)

	got, err := NewReader(getterFromPutter{putter}, "raw").Get(context.Background(), testRef, locator(body))

	require.NoError(t, err)
	assert.JSONEq(t, body, string(got))
}

func TestReaderRejectsChecksumMismatchInvalidKeyAndMissingObject(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), testRef, page(`{"a":1}`))
	require.NoError(t, err)
	reader := NewReader(getterFromPutter{putter}, "raw")

	// Объект лежит под ключом другого содержимого: сверка sha256.
	other := locator("other")
	putter.objects[mustKey(t, testRef, other)] = putter.objects[stored.Key]
	_, err = reader.Get(context.Background(), testRef, other)
	require.ErrorIs(t, err, ErrChecksumMismatch)
	require.ErrorIs(t, err, ErrObjectInvalid)

	bad := locator(`{"a":1}`)
	bad.ResourceType = "../../raw/v1"
	_, err = reader.Get(context.Background(), testRef, bad)
	require.ErrorIs(t, err, ErrObjectInvalid)
	require.ErrorIs(t, err, ErrInvalidKey)

	_, err = reader.Get(context.Background(), testRef, locator("missing"))
	require.ErrorIs(t, err, ErrObjectNotFound)

	broken := locator("broken")
	putter.objects[mustKey(t, testRef, broken)] = []byte("not gzip")
	_, err = reader.Get(context.Background(), testRef, broken)
	require.ErrorIs(t, err, ErrObjectInvalid)
}

// Чтение под подключением другой компании ищет объект в её префиксе, а не
// в префиксе владельца: тот же sha256 чужой компании недоступен.
func TestReaderDoesNotReadObjectOfAnotherCompany(t *testing.T) {
	putter := &fakePutter{}
	body := `{"a":1}`
	_, err := New(putter, "raw", 1).Put(context.Background(), accounttest.Ref("company-a"), page(body))
	require.NoError(t, err)
	getter := &recordingGetter{getterFromPutter{putter}, nil}

	_, err = NewReader(getter, "raw").Get(context.Background(), accounttest.Ref("company-b"), locator(body))

	require.ErrorIs(t, err, ErrObjectNotFound)
	require.Len(t, getter.keys, 1)
	assert.True(t, strings.HasPrefix(getter.keys[0], ConnectionPrefix(accounttest.Ref("company-b"))))
}

type recordingGetter struct {
	getterFromPutter
	keys []string
}

func (g *recordingGetter) GetObject(ctx context.Context, in *s3.GetObjectInput, opts ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	g.keys = append(g.keys, aws.ToString(in.Key))
	return g.getterFromPutter.GetObject(ctx, in, opts...)
}

func mustKey(t *testing.T, ref account.Ref, loc Locator) string {
	t.Helper()
	key, err := Key(ref, loc)
	require.NoError(t, err)
	return key
}

func TestReaderKeepsTransientErrorsRetryable(t *testing.T) {
	_, err := NewReader(failingGetter{errors.New("connection reset")}, "raw").Get(context.Background(), testRef, locator("a"))
	require.Error(t, err)
	assert.NotErrorIs(t, err, ErrObjectInvalid)
	assert.NotErrorIs(t, err, ErrObjectNotFound)
}

type failingGetter struct{ err error }

func (f failingGetter) GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	return nil, f.err
}

func sha256Of(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

// brokenBody — тело ответа, которое обрывается посреди чтения.
type brokenBody struct{ data []byte }

func (b *brokenBody) Read(p []byte) (int, error) {
	if len(b.data) == 0 {
		return 0, errors.New("connection reset by peer")
	}
	n := copy(p, b.data[:min(len(b.data), 4)])
	b.data = b.data[n:]
	return n, nil
}

func (b *brokenBody) Close() error { return nil }

type bodyGetter struct{ body io.ReadCloser }

func (g bodyGetter) GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	return &s3.GetObjectOutput{Body: g.body}, nil
}

func TestReaderBodyReadFailureIsTransient(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), testRef, page(`{"postings":[]}`))
	require.NoError(t, err)
	partial := putter.objects[stored.Key][:10]

	_, err = NewReader(bodyGetter{&brokenBody{data: append([]byte(nil), partial...)}}, "raw").Get(context.Background(), testRef, locator(`{"postings":[]}`))

	require.Error(t, err)
	assert.NotErrorIs(t, err, ErrObjectInvalid, "обрыв соединения — повтор, не повреждение")
}

func TestReaderTruncatedGzipIsInvalid(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), testRef, page(`{"postings":[{"posting_number":"P-1"}]}`))
	require.NoError(t, err)
	truncated := putter.objects[stored.Key][:len(putter.objects[stored.Key])-6]

	_, err = NewReader(bodyGetter{io.NopCloser(bytes.NewReader(truncated))}, "raw").Get(context.Background(), testRef, locator(`{"postings":[{"posting_number":"P-1"}]}`))

	require.ErrorIs(t, err, ErrObjectInvalid, "полностью прочитанный, но битый gzip — повреждение")
}
