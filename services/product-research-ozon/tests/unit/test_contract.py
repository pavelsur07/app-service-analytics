import json
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
