import json
import logging
from dataclasses import asdict, dataclass


@dataclass(frozen=True)
class Event:
    service: str = "product-research-ozon"
    company_id: str | None = None
    source_connection_id: str | None = None
    marketplace_account_id: str | None = None
    job_id: str | None = None
    trace_id: str | None = None
    resource_type: str = "infrastructure"
    outcome: str = "ok"
    error_class: str | None = None
    duration_ms: int = 0


class SafeJsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        event: object = getattr(record, "research_event", None)
        if not isinstance(event, Event):
            # Dependency logs and exception tracebacks can carry request bodies/DSNs.
            # Keep severity and class, never interpolate their arbitrary message.
            event = Event(
                outcome="error"
                if record.levelno >= logging.ERROR
                else "warning"
                if record.levelno >= logging.WARNING
                else "ok",
                error_class=record.exc_info[0].__name__
                if record.exc_info and record.exc_info[0]
                else None,
            )
        return json.dumps(asdict(event))


def emit(logger: logging.Logger, event: Event, level: int = logging.INFO) -> None:
    logger.log(level, "service_event", extra={"research_event": event})
