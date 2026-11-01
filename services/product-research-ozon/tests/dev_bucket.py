"""Explicit idempotent provisioning for the sandbox bucket only."""

import os

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError


def ensure_bucket() -> None:
    endpoint = os.environ["RESEARCH_S3_ENDPOINT"]
    bucket = os.environ["RESEARCH_S3_BUCKET"]
    if endpoint != "http://s3:8333" or bucket not in (
        "conwix-dev",
        "conwix-test",
    ):
        raise ValueError("sandbox_only")
    client = boto3.client(
        "s3",
        endpoint_url=endpoint,
        aws_access_key_id=os.environ["RESEARCH_S3_ACCESS_KEY"],
        aws_secret_access_key=os.environ["RESEARCH_S3_SECRET_KEY"],
        region_name="us-east-1",
        config=Config(
            connect_timeout=2,
            read_timeout=2,
            retries={"total_max_attempts": 1},
            s3={"addressing_style": "path"},
        ),
    )
    try:
        client.create_bucket(Bucket=bucket)
    except ClientError as failure:
        if failure.response["Error"]["Code"] not in (
            "BucketAlreadyExists",
            "BucketAlreadyOwnedByYou",
        ):
            raise
        client.head_bucket(Bucket=bucket)


if __name__ == "__main__":
    ensure_bucket()
