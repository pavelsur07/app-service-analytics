"""Offline acceptance of captured data. No MPStats credentials or network."""

import csv
import gzip
import hashlib
import io
import json
import tarfile
import unittest
import zipfile
from decimal import Decimal
from pathlib import Path
from typing import TypedDict, cast

ROOT = Path(__file__).parent / "fixtures/mpstats/ozon/category-items"


class Asset(TypedDict):
    path: str
    sha256: str


class PageAsset(Asset):
    rows: int
    start_row: int
    end_row: int


class Article(TypedDict):
    id: int
    sales: int
    revenue: Decimal | int


class Page(TypedDict):
    startRow: int
    endRow: int
    total: int
    data: list[Article]


class Manifest(TypedDict):
    assets: list[Asset]
    pages: list[PageAsset]
    csv_member: Asset


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def csv_metrics(
    raw: bytes, sku: str, sales: str, revenue: str
) -> dict[str, tuple[Decimal, Decimal]]:
    result: dict[str, tuple[Decimal, Decimal]] = {}
    for row in csv.DictReader(io.StringIO(raw.decode("utf-8-sig")), delimiter=";"):
        key = row[sku]
        if key in result:
            raise AssertionError(f"duplicate SKU: {key}")
        result[key] = (
            Decimal(row[sales].replace(",", ".")),
            Decimal(row[revenue].replace(",", ".")),
        )
    return result


def zip_csv(path: Path) -> bytes:
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != 1:
            raise AssertionError("expected one CSV")
        return archive.read(names[0])


class CapturedDataTest(unittest.TestCase):
    def test_full_month_is_byte_exact_and_matches_csv(self) -> None:
        folder = ROOT / "2026-01"
        manifest = cast(Manifest, json.loads((folder / "manifest.json").read_bytes()))
        for asset in manifest["assets"]:
            self.assertEqual(
                digest((folder / asset["path"]).read_bytes()), asset["sha256"]
            )
        metrics: dict[str, tuple[Decimal, Decimal]] = {}
        previous: tuple[Decimal, int] | None = None
        with tarfile.open(folder / "json-pages.tar.gz", "r:gz") as archive:
            self.assertEqual(archive.getnames(), [p["path"] for p in manifest["pages"]])
            self.assertEqual(len(manifest["pages"]), 102)
            for asset in manifest["pages"]:
                member = archive.extractfile(asset["path"])
                assert member is not None
                raw = member.read()
                self.assertEqual(digest(raw), asset["sha256"])
                page = cast(Page, json.loads(raw, parse_float=Decimal))
                self.assertEqual(page["total"], 50702)
                self.assertEqual(page["startRow"], asset["start_row"])
                self.assertEqual(page["endRow"], asset["end_row"])
                self.assertEqual(len(page["data"]), asset["rows"])
                for row in page["data"]:
                    key = str(row["id"])
                    self.assertNotIn(key, metrics)
                    order = (-Decimal(row["revenue"]), row["id"])
                    if previous is not None:
                        self.assertLessEqual(previous, order)
                    previous = order
                    metrics[key] = Decimal(row["sales"]), Decimal(row["revenue"])
        csv_raw = zip_csv(folder / "export.zip")
        self.assertEqual(digest(csv_raw), manifest["csv_member"]["sha256"])
        self.assertEqual(
            metrics, csv_metrics(csv_raw, "SKU", "Sales, pcs", "Revenue, ₽")
        )
        self.assertEqual(len(metrics), 50702)
        self.assertEqual(
            sum((m[0] for m in metrics.values()), Decimal(0)), Decimal(3541442)
        )
        self.assertEqual(
            sum((m[1] for m in metrics.values()), Decimal(0)), Decimal(5172621135)
        )

    def test_manual_export_confirms_fbs_and_preserves_diagnostic_difference(
        self,
    ) -> None:
        folder = ROOT / "insect-protection/2025-02"
        manifest = cast(Manifest, json.loads((folder / "manifest.json").read_bytes()))
        for asset in manifest["assets"]:
            self.assertEqual(
                digest((folder / asset["path"]).read_bytes()), asset["sha256"]
            )
        fbs = csv_metrics(
            zip_csv(folder / "api-fbs1.zip"), "SKU", "Sales, pcs", "Revenue, ₽"
        )
        fbo = csv_metrics(
            zip_csv(folder / "api-fbs0.zip"), "SKU", "Sales, pcs", "Revenue, ₽"
        )
        manual = csv_metrics(
            zip_csv(folder / "manual-fbs1.zip"), "SKU", "Sales", "Revenue"
        )
        old = csv_metrics(
            gzip.decompress((folder / "manual-previous.csv.gz").read_bytes()),
            "Артикул",
            "Продажи",
            "Выручка",
        )
        self.assertEqual(manual, fbs)
        self.assertEqual(old, fbo)
        self.assertEqual(fbs.keys(), fbo.keys())
        self.assertEqual(len(fbs), 62148)
        self.assertEqual(sum(a != fbo[k] for k, a in fbs.items()), 14347)
        self.assertEqual(
            sum((m[0] for m in fbs.values()), Decimal(0)), Decimal(1080978)
        )
        self.assertEqual(
            sum((m[1] for m in fbs.values()), Decimal(0)), Decimal(596919032)
        )
        self.assertEqual(sum((m[0] for m in fbo.values()), Decimal(0)), Decimal(314945))
        self.assertEqual(
            sum((m[1] for m in fbo.values()), Decimal(0)), Decimal(152581452)
        )


if __name__ == "__main__":
    unittest.main()
