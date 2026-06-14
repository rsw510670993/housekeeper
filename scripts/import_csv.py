#!/usr/bin/env python
from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import sqlite3
import sys
import unicodedata
from datetime import UTC, datetime
from pathlib import Path
from typing import Any


PAYPAY_HEADERS = {"利用日/キャンセル日", "利用店名・商品名", "利用金額", "当月お支払日"}
MUFG_HEADERS = {"日付", "摘要", "摘要内容", "支払い金額", "預かり金額", "差引残高"}


def now_utc() -> str:
    return datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S")


def migrate(conn: sqlite3.Connection) -> None:
    conn.executescript(
        """
CREATE TABLE IF NOT EXISTS imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    source_type TEXT,
    status TEXT NOT NULL,
    inserted_count INTEGER NOT NULL DEFAULT 0,
    duplicate_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type TEXT NOT NULL,
    transaction_date TEXT NOT NULL,
    description TEXT NOT NULL,
    counterparty_key TEXT NOT NULL,
    amount INTEGER NOT NULL,
    direction TEXT NOT NULL CHECK(direction IN ('expense', 'income')),
    balance INTEGER,
    payment_date TEXT,
    raw_json TEXT NOT NULL,
    unique_key TEXT NOT NULL,
    import_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(source_type, unique_key),
    FOREIGN KEY(import_id) REFERENCES imports(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL DEFAULT '#3b82f6',
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transaction_tags (
    transaction_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    origin TEXT NOT NULL CHECK(origin IN ('manual', 'auto')),
    created_at TEXT NOT NULL,
    PRIMARY KEY(transaction_id, tag_id),
    FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS transaction_links (
    parent_transaction_id INTEGER NOT NULL,
    child_transaction_id INTEGER NOT NULL,
    link_type TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY(parent_transaction_id, child_transaction_id, link_type),
    FOREIGN KEY(parent_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY(child_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
"""
    )


def decode_csv(path: Path) -> str:
    data = path.read_bytes()
    for encoding in ("utf-8-sig", "cp932", "shift_jis"):
        try:
            return data.decode(encoding)
        except UnicodeDecodeError:
            continue
    return data.decode("utf-8", errors="replace")


def read_rows(path: Path) -> list[dict[str, str]]:
    text = decode_csv(path)
    return list(csv.DictReader(text.splitlines()))


def normalize_key(value: str) -> str:
    value = unicodedata.normalize("NFKC", value)
    value = re.sub(r"\s+", " ", value.strip())
    return value


def parse_int(value: str | None) -> int | None:
    if value is None:
        return None
    cleaned = normalize_key(value).replace(",", "")
    if cleaned == "":
        return None
    return int(cleaned)


def parse_date(value: str) -> str:
    normalized = normalize_key(value)
    for fmt in ("%Y/%m/%d", "%Y-%m-%d"):
        try:
            return datetime.strptime(normalized, fmt).strftime("%Y-%m-%d")
        except ValueError:
            pass
    raise ValueError(f"Unsupported date: {value}")


def detect_source(rows: list[dict[str, str]]) -> str:
    if not rows:
        raise ValueError("CSV has no rows")
    headers = set(rows[0].keys())
    if PAYPAY_HEADERS.issubset(headers):
        return "paypay_card"
    if MUFG_HEADERS.issubset(headers):
        return "mufg_bank"
    raise ValueError(f"Unsupported CSV headers: {', '.join(headers)}")


def transaction_unique_key(source_type: str, item: dict[str, Any]) -> str:
    payload = {
        "source_type": source_type,
        "transaction_date": item["transaction_date"],
        "description": item["description"],
        "amount": item["amount"],
        "direction": item["direction"],
        "balance": item.get("balance"),
        "payment_date": item.get("payment_date"),
        "raw": item["raw"],
    }
    encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def transaction_dedup_signature(source_type: str, item: dict[str, Any]) -> tuple[str, str, str, int, str]:
    return (
        source_type,
        item["transaction_date"],
        item["counterparty_key"],
        item["amount"],
        item["direction"],
    )


def normalize_paypay(row: dict[str, str]) -> dict[str, Any]:
    description = normalize_key(row["利用店名・商品名"])
    amount = parse_int(row.get("利用金額"))
    if amount is None:
        raise ValueError("PayPay row has empty amount")
    return {
        "transaction_date": parse_date(row["利用日/キャンセル日"]),
        "description": description,
        "counterparty_key": description,
        "amount": abs(amount),
        "direction": "expense" if amount >= 0 else "income",
        "balance": None,
        "payment_date": parse_date(row["当月お支払日"]) if row.get("当月お支払日") else None,
        "raw": row,
    }


def normalize_mufg(row: dict[str, str]) -> dict[str, Any]:
    summary = normalize_key(row.get("摘要", ""))
    detail = normalize_key(row.get("摘要内容", ""))
    description = " ".join(part for part in (summary, detail) if part)
    paid = parse_int(row.get("支払い金額"))
    received = parse_int(row.get("預かり金額"))
    if paid is not None:
        amount = paid
        direction = "expense"
    elif received is not None:
        amount = received
        direction = "income"
    else:
        raise ValueError("MUFG row has neither paid nor received amount")
    return {
        "transaction_date": parse_date(row["日付"]),
        "description": description,
        "counterparty_key": normalize_key(f"{summary} {detail}"),
        "amount": abs(amount),
        "direction": direction,
        "balance": parse_int(row.get("差引残高")),
        "payment_date": None,
        "raw": row,
    }


def normalize_rows(source_type: str, rows: list[dict[str, str]]) -> list[dict[str, Any]]:
    normalizer = normalize_paypay if source_type == "paypay_card" else normalize_mufg
    items: list[dict[str, Any]] = []
    occurrence_counts: dict[tuple[str, str, str, int, str], int] = {}
    for row in rows:
        if not any((value or "").strip() for value in row.values()):
            continue
        item = normalizer(row)
        dedup_signature = transaction_dedup_signature(source_type, item)
        occurrence_counts[dedup_signature] = occurrence_counts.get(dedup_signature, 0) + 1
        occurrence = occurrence_counts[dedup_signature]
        base_unique_key = transaction_unique_key(source_type, item)
        item["dedup_occurrence"] = occurrence
        item["unique_key"] = base_unique_key if occurrence == 1 else f"{base_unique_key}:{occurrence}"
        items.append(item)
    return items


def create_import(conn: sqlite3.Connection, filename: str) -> int:
    cur = conn.execute(
        "INSERT INTO imports(filename, status, created_at) VALUES(?, ?, ?)",
        (filename, "running", now_utc()),
    )
    return int(cur.lastrowid)


def finish_import(
    conn: sqlite3.Connection,
    import_id: int,
    source_type: str | None,
    status: str,
    inserted_count: int,
    duplicate_count: int,
    error_message: str | None = None,
) -> None:
    conn.execute(
        """
UPDATE imports
SET source_type = ?, status = ?, inserted_count = ?, duplicate_count = ?, error_message = ?
WHERE id = ?
""",
        (source_type, status, inserted_count, duplicate_count, error_message, import_id),
    )


def latest_manual_tag_source(conn: sqlite3.Connection, source_type: str, counterparty_key: str) -> int | None:
    row = conn.execute(
        """
SELECT t.id
FROM transactions t
JOIN transaction_tags tt ON tt.transaction_id = t.id
WHERE t.source_type = ? AND t.counterparty_key = ? AND tt.origin = 'manual'
GROUP BY t.id
ORDER BY MAX(tt.created_at) DESC, t.id DESC
LIMIT 1
""",
        (source_type, counterparty_key),
    ).fetchone()
    return int(row[0]) if row else None


def inherit_tags(conn: sqlite3.Connection, source_transaction_id: int, target_transaction_id: int) -> None:
    tag_rows = conn.execute(
        "SELECT tag_id FROM transaction_tags WHERE transaction_id = ? AND origin = 'manual'",
        (source_transaction_id,),
    ).fetchall()
    for (tag_id,) in tag_rows:
        conn.execute(
            """
INSERT OR IGNORE INTO transaction_tags(transaction_id, tag_id, origin, created_at)
VALUES(?, ?, 'auto', ?)
""",
            (target_transaction_id, int(tag_id), now_utc()),
        )


def reconcile_card_statement_links(conn: sqlite3.Connection) -> int:
    conn.execute("DELETE FROM transaction_links WHERE link_type = 'card_statement'")
    groups = conn.execute(
        """
SELECT payment_date, SUM(amount) AS total_amount
FROM transactions
WHERE source_type = 'paypay_card' AND payment_date IS NOT NULL
GROUP BY payment_date
"""
    ).fetchall()
    linked_count = 0
    for payment_date, total_amount in groups:
        parent = conn.execute(
            """
SELECT id
FROM transactions
WHERE source_type = 'mufg_bank'
  AND transaction_date = ?
  AND amount = ?
  AND direction = 'expense'
  AND (description LIKE '%PAYPAY%' OR counterparty_key LIKE '%PAYPAY%')
ORDER BY id DESC
LIMIT 1
""",
            (payment_date, int(total_amount)),
        ).fetchone()
        if parent is None:
            continue
        child_rows = conn.execute(
            """
SELECT id
FROM transactions
WHERE source_type = 'paypay_card' AND payment_date = ?
ORDER BY transaction_date, id
""",
            (payment_date,),
        ).fetchall()
        for (child_id,) in child_rows:
            conn.execute(
                """
INSERT OR IGNORE INTO transaction_links(parent_transaction_id, child_transaction_id, link_type, created_at)
VALUES(?, ?, 'card_statement', ?)
""",
                (int(parent[0]), int(child_id), now_utc()),
            )
            linked_count += 1
    return linked_count


def import_file(db_path: Path, csv_path: Path, allowed_sources: set[str] | None = None) -> dict[str, Any]:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(db_path)
    conn.execute("PRAGMA foreign_keys = ON")
    migrate(conn)

    import_id = create_import(conn, csv_path.name)
    source_type: str | None = None
    inserted_count = 0
    duplicate_count = 0

    try:
        rows = read_rows(csv_path)
        source_type = detect_source(rows)
        if allowed_sources is not None and source_type not in allowed_sources:
            raise ValueError(f"Source is disabled: {source_type}")
        items = normalize_rows(source_type, rows)
        for item in items:
            existing_count = conn.execute(
                """
SELECT COUNT(*)
FROM transactions
WHERE source_type = ?
  AND transaction_date = ?
  AND counterparty_key = ?
  AND amount = ?
  AND direction = ?
""",
                (
                    source_type,
                    item["transaction_date"],
                    item["counterparty_key"],
                    item["amount"],
                    item["direction"],
                ),
            ).fetchone()[0]
            if int(existing_count) >= item["dedup_occurrence"]:
                duplicate_count += 1
                continue

            created = now_utc()
            cur = conn.execute(
                """
INSERT INTO transactions(
    source_type, transaction_date, description, counterparty_key, amount, direction,
    balance, payment_date, raw_json, unique_key, import_id, created_at, updated_at
) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
""",
                (
                    source_type,
                    item["transaction_date"],
                    item["description"],
                    item["counterparty_key"],
                    item["amount"],
                    item["direction"],
                    item.get("balance"),
                    item.get("payment_date"),
                    json.dumps(item["raw"], ensure_ascii=False, sort_keys=True),
                    item["unique_key"],
                    import_id,
                    created,
                    created,
                ),
            )
            tx_id = int(cur.lastrowid)
            source_tx_id = latest_manual_tag_source(conn, source_type, item["counterparty_key"])
            if source_tx_id is not None:
                inherit_tags(conn, source_tx_id, tx_id)
            inserted_count += 1

        linked_count = reconcile_card_statement_links(conn)
        finish_import(conn, import_id, source_type, "success", inserted_count, duplicate_count)
        conn.commit()
        return {
            "import_id": import_id,
            "source_type": source_type,
            "inserted_count": inserted_count,
            "duplicate_count": duplicate_count,
            "linked_count": linked_count,
            "status": "success",
        }
    except Exception as exc:
        conn.rollback()
        conn.execute(
            "INSERT INTO imports(id, filename, source_type, status, inserted_count, duplicate_count, error_message, created_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?) "
            "ON CONFLICT(id) DO UPDATE SET source_type=excluded.source_type, status=excluded.status, inserted_count=excluded.inserted_count, duplicate_count=excluded.duplicate_count, error_message=excluded.error_message",
            (import_id, csv_path.name, source_type, "failed", inserted_count, duplicate_count, str(exc), now_utc()),
        )
        conn.commit()
        raise
    finally:
        conn.close()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Import bank and credit-card CSV files into Housekeeper SQLite.")
    parser.add_argument("--db", required=True, type=Path)
    parser.add_argument("--file", required=True, type=Path)
    parser.add_argument("--allow-source", action="append", dest="allow_sources")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    try:
        allowed_sources = set(args.allow_sources) if args.allow_sources else None
        result = import_file(args.db, args.file, allowed_sources)
    except Exception as exc:
        if args.json:
            print(json.dumps({"status": "failed", "error": str(exc)}, ensure_ascii=False))
        else:
            print(f"Import failed: {exc}", file=sys.stderr)
        return 1

    if args.json:
        print(json.dumps(result, ensure_ascii=False))
    else:
        print(
            f"Imported {result['inserted_count']} transactions "
            f"({result['duplicate_count']} duplicates) from {result['source_type']}."
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
