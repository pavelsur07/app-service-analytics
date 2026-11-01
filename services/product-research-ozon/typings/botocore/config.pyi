class Config:
    def __init__(
        self,
        *,
        connect_timeout: int,
        read_timeout: int,
        retries: dict[str, int],
        s3: dict[str, str],
    ) -> None: ...
