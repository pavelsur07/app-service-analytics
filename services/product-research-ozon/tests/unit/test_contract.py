import json
from datetime import datetime
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker

CONTRACT = Path("/contracts/product-research-ozon/v1")


def test_shared_corpus() -> None:
    schema = json.loads((CONTRACT / "schema.json").read_text())
    cases = json.loads((CONTRACT / "examples.json").read_text())
    assert len(cases) >= 20
    for case in cases:
        selected = {**schema, "oneOf": [{"$ref": "#/$defs/" + case["definition"]}]}
        validator = Draft202012Validator(selected, format_checker=FormatChecker())
        assert validator.is_valid(case["payload"]) == case["valid"], case["name"]


def test_v2_progress_corpus() -> None:
    contract = CONTRACT.parent / "v2"
    schema = json.loads((contract / "schema.json").read_text())
    cases = json.loads((contract / "examples.json").read_text())
    checker = FormatChecker()

    @checker.checks("date-time", raises=ValueError)
    def aware_datetime(value: object) -> bool:
        return (
            not isinstance(value, str)
            or datetime.fromisoformat(value).tzinfo is not None
        )

    for case in cases:
        validator = Draft202012Validator(schema, format_checker=checker)
        assert validator.is_valid(case["payload"]) == case["valid"], case["name"]
