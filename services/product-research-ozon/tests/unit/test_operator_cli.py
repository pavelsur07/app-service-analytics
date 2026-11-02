import json
import sys

import pytest

from product_research_ozon import operations


def test_cli_never_prints_configuration_or_sdk_tracebacks(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    def broken() -> None:
        raise RuntimeError("private-token-dsn-body")

    monkeypatch.setattr(operations, "configured_settings", broken)
    monkeypatch.setattr(
        sys,
        "argv",
        [
            "operations",
            "diagnostics",
            "019a0000-0000-7000-8000-000000000001",
            "019a0000-0000-7000-8000-000000000002",
        ],
    )
    with pytest.raises(SystemExit) as error:
        operations.main()
    assert error.value.code == 1
    captured = capsys.readouterr()
    assert captured.err == ""
    assert json.loads(captured.out) == {
        "status": "error",
        "code": "operation_unavailable",
    }
    assert "private" not in captured.out
