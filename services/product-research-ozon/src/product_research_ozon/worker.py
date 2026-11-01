import json
import logging
import logging.config
from pathlib import Path

from product_research_ozon.bootstrap import configured_settings
from product_research_ozon.infrastructure.observability import Event, emit


def main() -> None:
    logging.config.dictConfig(json.loads(Path("/service/logging.json").read_text()))
    configured_settings()
    emit(
        logging.getLogger("product-research-ozon"),
        Event(outcome="disabled", resource_type="worker"),
    )


if __name__ == "__main__":
    main()
