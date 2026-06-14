from __future__ import annotations

import csv
import sqlite3
import tempfile
import unittest
from pathlib import Path

import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from import_csv import import_file  # noqa: E402


class ImportCsvTest(unittest.TestCase):
    def test_paypay_import_and_duplicate_skip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            csv_path = root / "paypay.csv"
            db_path = root / "housekeeper.sqlite"
            self.write_csv(
                csv_path,
                [
                    "利用日/キャンセル日",
                    "利用店名・商品名",
                    "利用者",
                    "決済方法",
                    "支払区分",
                    "利用金額",
                    "手数料",
                    "支払総額",
                    "当月支払金額",
                    "翌月以降繰越金額",
                    "調整額",
                    "当月お支払日",
                ],
                [["2026/4/1", "OPENROUTER, INC利用国USN", "本人*", "PayPayカード ゴールド", "1回", "1784", "0", "1784", "1784", "0", "0", "2026/5/27"]],
                "utf-8-sig",
            )

            first = import_file(db_path, csv_path)
            second = import_file(db_path, csv_path)

            self.assertEqual(first["source_type"], "paypay_card")
            self.assertEqual(first["inserted_count"], 1)
            self.assertEqual(second["duplicate_count"], 1)

            conn = sqlite3.connect(db_path)
            row = conn.execute(
                "SELECT transaction_date, description, amount, direction, payment_date FROM transactions"
            ).fetchone()
            conn.close()
            self.assertEqual(row, ("2026-04-01", "OPENROUTER, INC利用国USN", 1784, "expense", "2026-05-27"))

    def test_dedup_requires_exact_date_store_and_amount(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            csv_path = root / "paypay-distinct.csv"
            db_path = root / "housekeeper.sqlite"
            base = ["2026/2/14", "STORE A", "user", "card", "once", "1220", "0", "1220", "1220", "0", "0", "2026/3/27"]
            rows = [
                base,
                ["2026/2/15", *base[1:]],
                [base[0], "STORE B", *base[2:]],
                [*base[:5], "1221", "0", "1221", "1221", "0", "0", base[11]],
            ]
            self.write_csv(csv_path, self.paypay_header(), rows, "utf-8-sig")

            first = import_file(db_path, csv_path)
            second = import_file(db_path, csv_path)

            conn = sqlite3.connect(db_path)
            count = conn.execute("SELECT COUNT(*) FROM transactions").fetchone()[0]
            conn.close()
            self.assertEqual(first["inserted_count"], 4)
            self.assertEqual(second["duplicate_count"], 4)
            self.assertEqual(count, 4)

    def test_same_date_store_amount_is_duplicate_even_if_other_fields_change(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            first_csv = root / "first.csv"
            second_csv = root / "second.csv"
            db_path = root / "housekeeper.sqlite"
            first_row = ["2026/2/14", "STORE A", "user-a", "card-a", "once", "1220", "0", "1220", "1220", "0", "0", "2026/3/27"]
            second_row = ["2026/2/14", "STORE A", "user-b", "card-b", "once", "1220", "0", "1220", "1220", "0", "0", "2026/4/27"]
            self.write_csv(first_csv, self.paypay_header(), [first_row], "utf-8-sig")
            self.write_csv(second_csv, self.paypay_header(), [second_row], "utf-8-sig")

            first = import_file(db_path, first_csv)
            second = import_file(db_path, second_csv)

            self.assertEqual(first["inserted_count"], 1)
            self.assertEqual(second["duplicate_count"], 1)

    def test_identical_rows_are_kept_but_reimport_is_skipped(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            csv_path = root / "paypay-identical.csv"
            db_path = root / "housekeeper.sqlite"
            row = ["2026/2/14", "Google", "??*", "PayPay?????", "1?", "1220", "0", "1220", "1220", "0", "0", "2026/3/27"]
            self.write_csv(csv_path, self.paypay_header(), [row, row], "utf-8-sig")

            first = import_file(db_path, csv_path)
            second = import_file(db_path, csv_path)

            conn = sqlite3.connect(db_path)
            count, total = conn.execute("SELECT COUNT(*), SUM(amount) FROM transactions").fetchone()
            keys = conn.execute("SELECT unique_key FROM transactions ORDER BY id").fetchall()
            conn.close()
            self.assertEqual(first["inserted_count"], 2)
            self.assertEqual(second["duplicate_count"], 2)
            self.assertEqual((count, total), (2, 2440))
            self.assertNotEqual(keys[0][0], keys[1][0])

    def test_mufg_cp932_import(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            csv_path = root / "mufg.csv"
            db_path = root / "housekeeper.sqlite"
            self.write_csv(
                csv_path,
                ["日付", "摘要", "摘要内容", "支払い金額", "預かり金額", "差引残高"],
                [
                    ["2026/1/3", "ＢＡＮＣＳ", "ミツイスミトモギンコウ", "50,000", "", "347,485"],
                    ["2026/1/23", "振込２", "カ）セブンプラス", "", "387,640", "684,575"],
                ],
                "cp932",
            )

            result = import_file(db_path, csv_path)

            self.assertEqual(result["source_type"], "mufg_bank")
            conn = sqlite3.connect(db_path)
            rows = conn.execute(
                "SELECT description, amount, direction, balance FROM transactions ORDER BY id"
            ).fetchall()
            conn.close()
            self.assertEqual(rows[0], ("BANCS ミツイスミトモギンコウ", 50000, "expense", 347485))
            self.assertEqual(rows[1], ("振込2 カ)セブンプラス", 387640, "income", 684575))

    def test_manual_tag_is_inherited_for_exact_counterparty_key(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            first_csv = root / "first.csv"
            second_csv = root / "second.csv"
            db_path = root / "housekeeper.sqlite"
            header = [
                "利用日/キャンセル日",
                "利用店名・商品名",
                "利用者",
                "決済方法",
                "支払区分",
                "利用金額",
                "手数料",
                "支払総額",
                "当月支払金額",
                "翌月以降繰越金額",
                "調整額",
                "当月お支払日",
            ]
            self.write_csv(first_csv, header, [["2026/4/1", "GOOGLE*CLOUD 5VGTF6", "本人*", "PayPayカード ゴールド", "1回", "487", "0", "487", "487", "0", "0", "2026/5/27"]], "utf-8-sig")
            self.write_csv(second_csv, header, [["2026/4/2", "GOOGLE*CLOUD 5VGTF6", "本人*", "PayPayカード ゴールド", "1回", "950", "0", "950", "950", "0", "0", "2026/5/27"]], "utf-8-sig")

            import_file(db_path, first_csv)
            conn = sqlite3.connect(db_path)
            conn.execute("INSERT INTO tags(name, created_at) VALUES('cloud', '2026-01-01 00:00:00')")
            conn.execute("INSERT INTO transaction_tags(transaction_id, tag_id, origin, created_at) VALUES(1, 1, 'manual', '2026-01-01 00:00:00')")
            conn.commit()
            conn.close()

            result = import_file(db_path, second_csv)

            self.assertEqual(result["inserted_count"], 1)
            conn = sqlite3.connect(db_path)
            tag_row = conn.execute(
                """
SELECT g.name, tt.origin
FROM transactions t
JOIN transaction_tags tt ON tt.transaction_id = t.id
JOIN tags g ON g.id = tt.tag_id
WHERE t.amount = 950
"""
            ).fetchone()
            conn.close()
            self.assertEqual(tag_row, ("cloud", "auto"))


    def test_paypay_links_to_mufg_card_payment_when_paypay_imports_first(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            db_path = root / "housekeeper.sqlite"
            paypay_csv = root / "paypay.csv"
            mufg_csv = root / "mufg.csv"
            self.write_csv(paypay_csv, self.paypay_header(), [
                ["2026/5/1", "STORE A", "??*", "PayPay??? ????", "1?", "100", "0", "100", "100", "0", "0", "2026/5/27"],
                ["2026/5/2", "STORE B", "??*", "PayPay??? ????", "1?", "200", "0", "200", "200", "0", "0", "2026/5/27"],
            ], "utf-8-sig")
            self.write_csv(mufg_csv, self.mufg_header(), [["2026/5/27", "????3", "PAYPAY?-?", "300", "", "1000"]], "cp932")

            import_file(db_path, paypay_csv)
            import_file(db_path, mufg_csv)

            conn = sqlite3.connect(db_path)
            rows = conn.execute("SELECT COUNT(*), COUNT(DISTINCT child_transaction_id) FROM transaction_links WHERE link_type = 'card_statement'").fetchone()
            conn.close()
            self.assertEqual(rows, (2, 2))

    def test_paypay_links_to_mufg_card_payment_when_mufg_imports_first(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            db_path = root / "housekeeper.sqlite"
            paypay_csv = root / "paypay.csv"
            mufg_csv = root / "mufg.csv"
            self.write_csv(mufg_csv, self.mufg_header(), [["2026/5/27", "????3", "PAYPAY?-?", "300", "", "1000"]], "cp932")
            self.write_csv(paypay_csv, self.paypay_header(), [
                ["2026/5/1", "STORE A", "??*", "PayPay??? ????", "1?", "100", "0", "100", "100", "0", "0", "2026/5/27"],
                ["2026/5/2", "STORE B", "??*", "PayPay??? ????", "1?", "200", "0", "200", "200", "0", "0", "2026/5/27"],
            ], "utf-8-sig")

            import_file(db_path, mufg_csv)
            first = import_file(db_path, paypay_csv)
            second = import_file(db_path, paypay_csv)

            conn = sqlite3.connect(db_path)
            rows = conn.execute("SELECT COUNT(*), COUNT(DISTINCT child_transaction_id) FROM transaction_links WHERE link_type = 'card_statement'").fetchone()
            conn.close()
            self.assertEqual(first["linked_count"], 2)
            self.assertEqual(second["duplicate_count"], 2)
            self.assertEqual(rows, (2, 2))

    def test_paypay_does_not_link_when_payment_amount_differs(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            db_path = root / "housekeeper.sqlite"
            paypay_csv = root / "paypay.csv"
            mufg_csv = root / "mufg.csv"
            self.write_csv(mufg_csv, self.mufg_header(), [["2026/5/27", "????3", "PAYPAY?-?", "999", "", "1000"]], "cp932")
            self.write_csv(paypay_csv, self.paypay_header(), [["2026/5/1", "STORE A", "??*", "PayPay??? ????", "1?", "100", "0", "100", "100", "0", "0", "2026/5/27"]], "utf-8-sig")

            import_file(db_path, mufg_csv)
            import_file(db_path, paypay_csv)

            conn = sqlite3.connect(db_path)
            count = conn.execute("SELECT COUNT(*) FROM transaction_links WHERE link_type = 'card_statement'").fetchone()[0]
            conn.close()
            self.assertEqual(count, 0)

    @staticmethod
    def paypay_header() -> list[str]:
        return [
            "\u5229\u7528\u65e5/\u30ad\u30e3\u30f3\u30bb\u30eb\u65e5",
            "\u5229\u7528\u5e97\u540d\u30fb\u5546\u54c1\u540d",
            "\u5229\u7528\u8005",
            "\u6c7a\u6e08\u65b9\u6cd5",
            "\u652f\u6255\u533a\u5206",
            "\u5229\u7528\u91d1\u984d",
            "\u624b\u6570\u6599",
            "\u652f\u6255\u7dcf\u984d",
            "\u5f53\u6708\u652f\u6255\u91d1\u984d",
            "\u7fcc\u6708\u4ee5\u964d\u7e70\u8d8a\u91d1\u984d",
            "\u8abf\u6574\u984d",
            "\u5f53\u6708\u304a\u652f\u6255\u65e5",
        ]

    @staticmethod
    def mufg_header() -> list[str]:
        return ["\u65e5\u4ed8", "\u6458\u8981", "\u6458\u8981\u5185\u5bb9", "\u652f\u6255\u3044\u91d1\u984d", "\u9810\u304b\u308a\u91d1\u984d", "\u5dee\u5f15\u6b8b\u9ad8"]

    @staticmethod
    def write_csv(path: Path, header: list[str], rows: list[list[str]], encoding: str) -> None:
        with path.open("w", newline="", encoding=encoding) as handle:
            writer = csv.writer(handle)
            writer.writerow(header)
            writer.writerows(rows)


if __name__ == "__main__":
    unittest.main()
