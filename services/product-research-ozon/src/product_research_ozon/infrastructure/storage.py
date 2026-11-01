import hashlib
from uuid import UUID

import boto3
from botocore.config import Config
from botocore.exceptions import BotoCoreError, ClientError

from product_research_ozon.domain.month import CollectionFailure
from product_research_ozon.domain.storage import ManifestRef, PageRef
from product_research_ozon.infrastructure.config import Settings


class RawStorage:
    def __init__(self, settings: Settings) -> None:
        self._bucket = settings.s3_bucket
        self._client = boto3.client(
            "s3",
            endpoint_url=settings.s3_endpoint,
            aws_access_key_id=settings.s3_access_key,
            aws_secret_access_key=settings.s3_secret_key,
            region_name="us-east-1",
            config=Config(
                connect_timeout=2,
                read_timeout=2,
                retries={"total_max_attempts": 1},
                s3={"addressing_style": "path"},
            ),
        )

    def ready(self) -> None:
        self._client.head_bucket(Bucket=self._bucket)

    def put_page(self, company_id: UUID, page: PageRef, body: bytes) -> None:
        key = page.key(company_id)
        if (
            len(body) > 16 * 1024 * 1024
            or hashlib.sha256(body).hexdigest() != page.sha256
        ):
            raise ValueError("invalid_page_body")
        self._client.put_object(
            Bucket=self._bucket,
            Key=key,
            Body=body,
            ContentType="application/json",
            IfNoneMatch="*",
        )

    def read_page(self, company_id: UUID, page: PageRef) -> bytes:
        key = page.key(company_id)
        response = self._client.get_object(Bucket=self._bucket, Key=key)
        with response["Body"] as stream:
            body = stream.read(16 * 1024 * 1024 + 1)
        if (
            len(body) > 16 * 1024 * 1024
            or hashlib.sha256(body).hexdigest() != page.sha256
        ):
            raise ValueError("corrupt_page")
        return body

    def save_page(self, company_id: UUID, page: PageRef, body: bytes) -> None:
        self._save_verified(company_id, page, body)

    def save_manifest(
        self, company_id: UUID, manifest: ManifestRef, body: bytes
    ) -> None:
        self._save_verified(company_id, manifest, body)

    def _save_verified(
        self, company_id: UUID, ref: PageRef | ManifestRef, body: bytes
    ) -> None:
        key = ref.key(company_id)
        if (
            len(body) > 16 * 1024 * 1024
            or hashlib.sha256(body).hexdigest() != ref.sha256
        ):
            raise CollectionFailure("corrupt_raw")
        try:
            try:
                self._client.put_object(
                    Bucket=self._bucket,
                    Key=key,
                    Body=body,
                    ContentType="application/json",
                    IfNoneMatch="*",
                )
            except ClientError as error:
                if error.response["Error"]["Code"] not in (
                    "PreconditionFailed",
                    "412",
                    "ConditionalRequestConflict",
                ):
                    raise
            # Verify successful writes as well as retries against an existing immutable object.
            if self.read_object(company_id, ref) != body:
                raise CollectionFailure("corrupt_raw")
        except BotoCoreError, ClientError:
            raise CollectionFailure("storage_unavailable") from None

    def read_object(self, company_id: UUID, ref: PageRef | ManifestRef) -> bytes:
        key = ref.key(company_id)
        try:
            response = self._client.get_object(Bucket=self._bucket, Key=key)
            with response["Body"] as stream:
                body = stream.read(16 * 1024 * 1024 + 1)
            if (
                len(body) > 16 * 1024 * 1024
                or hashlib.sha256(body).hexdigest() != ref.sha256
            ):
                raise CollectionFailure("corrupt_raw")
            return body
        except BotoCoreError, ClientError:
            raise CollectionFailure("storage_unavailable") from None
