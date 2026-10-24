package rawstore

import (
	"bytes"
	"context"
	"errors"
	"io"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/s3/types"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
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
	stored, err := store.Put(context.Background(), page(`{"postings":[{"posting_number":"P-1"}]}`))
	require.NoError(t, err)

	got, err := NewReader(getterFromPutter{putter}, "raw").Get(context.Background(), stored.URI, stored.SHA256)

	require.NoError(t, err)
	assert.JSONEq(t, `{"postings":[{"posting_number":"P-1"}]}`, string(got))
}

func TestReaderRejectsChecksumMismatchForeignBucketAndMissingObject(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), page(`{"a":1}`))
	require.NoError(t, err)
	reader := NewReader(getterFromPutter{putter}, "raw")

	_, err = reader.Get(context.Background(), stored.URI, sha256Of("other"))
	require.ErrorIs(t, err, ErrChecksumMismatch)

	require.ErrorIs(t, err, ErrObjectInvalid)

	_, err = NewReader(getterFromPutter{putter}, "other-bucket").Get(context.Background(), stored.URI, stored.SHA256)
	require.ErrorIs(t, err, ErrObjectInvalid)

	_, err = reader.Get(context.Background(), "s3://raw/missing.json.gz", stored.SHA256)
	require.ErrorIs(t, err, ErrObjectNotFound)

	putter.objects["broken.json.gz"] = []byte("not gzip")
	_, err = reader.Get(context.Background(), "s3://raw/broken.json.gz", stored.SHA256)
	require.ErrorIs(t, err, ErrObjectInvalid)
}

func TestReaderKeepsTransientErrorsRetryable(t *testing.T) {
	_, err := NewReader(failingGetter{errors.New("connection reset")}, "raw").Get(context.Background(), "s3://raw/a.json.gz", "x")
	require.Error(t, err)
	assert.NotErrorIs(t, err, ErrObjectInvalid)
	assert.NotErrorIs(t, err, ErrObjectNotFound)
}

type failingGetter struct{ err error }

func (f failingGetter) GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	return nil, f.err
}

func sha256Of(s string) string {
	stored, _ := New(&fakePutter{}, "x", 1).Put(context.Background(), page(s))
	return stored.SHA256
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
	stored, err := New(putter, "raw", 1).Put(context.Background(), page(`{"postings":[]}`))
	require.NoError(t, err)
	partial := putter.objects[stored.Key][:10]

	_, err = NewReader(bodyGetter{&brokenBody{data: append([]byte(nil), partial...)}}, "raw").Get(context.Background(), stored.URI, stored.SHA256)

	require.Error(t, err)
	assert.NotErrorIs(t, err, ErrObjectInvalid, "обрыв соединения — повтор, не повреждение")
}

func TestReaderTruncatedGzipIsInvalid(t *testing.T) {
	putter := &fakePutter{}
	stored, err := New(putter, "raw", 1).Put(context.Background(), page(`{"postings":[{"posting_number":"P-1"}]}`))
	require.NoError(t, err)
	truncated := putter.objects[stored.Key][:len(putter.objects[stored.Key])-6]

	_, err = NewReader(bodyGetter{io.NopCloser(bytes.NewReader(truncated))}, "raw").Get(context.Background(), stored.URI, stored.SHA256)

	require.ErrorIs(t, err, ErrObjectInvalid, "полностью прочитанный, но битый gzip — повреждение")
}
