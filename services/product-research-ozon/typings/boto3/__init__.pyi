from types import TracebackType
from typing import Self, TypedDict

from botocore.config import Config

class Body:
    def read(self, size: int) -> bytes: ...
    def __enter__(self) -> Self: ...
    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        traceback: TracebackType | None,
    ) -> None: ...

class GetResponse(TypedDict):
    Body: Body

class S3:
    def head_bucket(self, *, Bucket: str) -> object: ...
    def create_bucket(self, *, Bucket: str) -> object: ...
    def put_object(
        self, *, Bucket: str, Key: str, Body: bytes, ContentType: str, IfNoneMatch: str
    ) -> object: ...
    def get_object(self, *, Bucket: str, Key: str) -> GetResponse: ...
    def delete_object(self, *, Bucket: str, Key: str) -> object: ...

def client(
    service_name: str,
    *,
    endpoint_url: str,
    aws_access_key_id: str,
    aws_secret_access_key: str,
    region_name: str,
    config: Config,
) -> S3: ...
