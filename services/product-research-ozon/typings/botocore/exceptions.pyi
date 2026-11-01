from typing import TypedDict

class ErrorInfo(TypedDict):
    Code: str

class ErrorResponse(TypedDict):
    Error: ErrorInfo

class BotoCoreError(Exception): ...

class ClientError(Exception):
    response: ErrorResponse
