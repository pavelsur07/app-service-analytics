package rawstore

import (
	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

// NewS3Client — S3-совместимый клиент (Timeweb, MinIO): endpoint и region из
// env, path-style адресация, статические ключи доп. пользователя бакета.
func NewS3Client(endpoint, region, accessKeyID, secretAccessKey string) *s3.Client {
	return s3.New(s3.Options{
		BaseEndpoint: aws.String(endpoint),
		Region:       region,
		Credentials:  aws.NewCredentialsCache(credentials.NewStaticCredentialsProvider(accessKeyID, secretAccessKey, "")),
		UsePathStyle: true,
	})
}
