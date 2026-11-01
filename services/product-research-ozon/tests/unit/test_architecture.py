import os
import shutil
import subprocess
from pathlib import Path


def test_forbidden_import_is_detected(tmp_path: Path) -> None:
    source = Path("/service/src")
    shutil.copytree(source, tmp_path / "src")
    shutil.copy("/service/pyproject.toml", tmp_path / "pyproject.toml")
    injected = tmp_path / "src/product_research_ozon/domain/storage.py"
    injected.write_text(injected.read_text() + "\nimport psycopg\n")
    result = subprocess.run(
        ["lint-imports", "--no-cache"],
        check=False,
        cwd=tmp_path,
        env={**os.environ, "PYTHONPATH": str(tmp_path / "src")},
        capture_output=True,
        text=True,
        timeout=30,
    )
    assert result.returncode != 0
    assert "BROKEN" in result.stdout
    assert "psycopg" in result.stdout
