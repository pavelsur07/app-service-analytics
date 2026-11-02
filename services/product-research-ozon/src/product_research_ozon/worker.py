import json
import logging
import logging.config
import signal
import sys
import time
from pathlib import Path
from types import FrameType

from product_research_ozon.bootstrap import configured_settings, configured_worker
from product_research_ozon.domain.month import CollectionFailure
from product_research_ozon.infrastructure.config import Settings
from product_research_ozon.infrastructure.observability import Event, emit
from product_research_ozon.infrastructure.readiness import Readiness
from product_research_ozon.infrastructure.storage import RawStorage


def ready(settings: Settings) -> bool:
    return (
        settings.worker_standby
        or (settings.collection is not None and settings.collection.worker_enabled)
    ) and Readiness(settings, RawStorage(settings))()


def main() -> None:
    logging.config.dictConfig(json.loads(Path("/service/logging.json").read_text()))
    settings = configured_settings()
    logger = logging.getLogger("product-research-ozon")
    if not settings.worker_standby and (
        settings.collection is None or not settings.collection.worker_enabled
    ):
        emit(logger, Event(outcome="disabled", resource_type="worker"))
        return
    if not ready(settings):
        emit(
            logger, Event(outcome="not_ready", resource_type="worker"), logging.WARNING
        )
        raise SystemExit(1)
    stopping = False

    def stop(signum: int, frame: FrameType | None) -> None:
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    if settings.worker_standby:
        emit(logger, Event(outcome="standby", resource_type="worker"))
        while not stopping:
            time.sleep(1)
        return
    worker = configured_worker(settings)
    while not stopping:
        try:
            worked = worker.run_once()
        except CollectionFailure as failure:
            emit(
                logger,
                Event(
                    outcome="error", resource_type="worker", error_class=failure.code
                ),
                logging.ERROR,
            )
            worked = False
        if not worked and not stopping:
            # Idle only. Provider waits are persisted in jobs and never sleep here.
            time.sleep(1)


if __name__ == "__main__":
    if sys.argv[1:] == ["--check"]:
        raise SystemExit(0 if ready(configured_settings()) else 1)
    main()
