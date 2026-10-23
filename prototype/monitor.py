"""exchange-watcher — прототип мониторинга обмена с 1С.

Read-only. Читает CSV-лог обмена, применяет правила свежести и ошибок,
печатает отчёт и алерты в Telegram. Сохраняет state.json для дедупа.

Запуск:
    python3 monitor.py --now 2026-10-23T12:30:00 --no-state
    python3 monitor.py --now 2026-10-23T12:30:00
"""
from __future__ import annotations

import argparse
import csv
import json
import sys
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path

DEFAULT_LOG = Path(__file__).parent / "fake_exchange_log.csv"
DEFAULT_STATE = Path(__file__).parent / "state.json"

# Пороги — калибруются на реальном baseline
WARN_AFTER_MIN = 20
CRIT_AFTER_MIN = 60
ERROR_WINDOW_MIN = 15
ERROR_THRESHOLD = 3


@dataclass
class Row:
    ts: datetime
    direction: str
    status: str
    rows: int
    message: str


@dataclass
class DirectionState:
    direction: str
    last_success: datetime | None = None
    last_event: datetime | None = None
    recent_errors: int = 0
    recent_error_lines: list[str] = field(default_factory=list)


def parse_ts(s: str) -> datetime:
    s = s.strip().replace(" ", "T")
    return datetime.fromisoformat(s).replace(tzinfo=timezone.utc)


def load_rows(path: Path) -> list[Row]:
    rows: list[Row] = []
    with path.open("r", encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        for r in reader:
            try:
                rows.append(Row(
                    ts=parse_ts(r["timestamp"]),
                    direction=r["direction"].strip(),
                    status=r["status"].strip().lower(),
                    rows=int(r.get("rows") or 0),
                    message=(r.get("message") or "").strip(),
                ))
            except Exception as e:  # noqa: BLE001
                print(f"[WARN] skip bad row: {r} ({e})", file=sys.stderr)
    rows.sort(key=lambda x: x.ts)
    return rows


def analyze(rows: list[Row], now: datetime) -> dict[str, DirectionState]:
    state: dict[str, DirectionState] = {}
    window_start = now - timedelta(minutes=ERROR_WINDOW_MIN)
    for r in rows:
        ds = state.setdefault(r.direction, DirectionState(direction=r.direction))
        if ds.last_event is None or r.ts > ds.last_event:
            ds.last_event = r.ts
        if r.status == "success":
            if ds.last_success is None or r.ts > ds.last_success:
                ds.last_success = r.ts
        elif r.status == "error" and r.ts >= window_start:
            ds.recent_errors += 1
            ds.recent_error_lines.append(
                f"{r.ts.isoformat(timespec='seconds')} {r.message}"
            )
            ds.recent_error_lines = ds.recent_error_lines[-5:]
    return state


def severity(ds: DirectionState, now: datetime) -> str:
    if ds.last_success is None:
        return "CRITICAL"  # R4
    if ds.recent_errors >= ERROR_THRESHOLD:
        return "CRITICAL"  # R3
    age_min = (now - ds.last_success).total_seconds() / 60
    if age_min >= CRIT_AFTER_MIN:
        return "CRITICAL"  # R1
    if age_min >= WARN_AFTER_MIN:
        return "WARNING"  # R2
    if ds.recent_errors > 0:
        return "WARNING"
    return "OK"


def humanize_minutes(minutes: float) -> str:
    if minutes < 1:
        return "< 1 мин"
    if minutes < 60:
        return f"{int(minutes)} мин"
    return f"{minutes / 60:.1f} ч"


def format_alert(direction: str, sev: str, ds: DirectionState, now: datetime) -> str:
    if ds.last_success:
        age = (now - ds.last_success).total_seconds() / 60
        last_success_str = ds.last_success.isoformat(timespec="seconds")
        age_str = humanize_minutes(age)
    else:
        last_success_str, age_str = "—", "никогда"

    lines = [
        f"🚨 [{sev}] Обмен с 1С: {direction}",
        f"Последний успех: {last_success_str} ({age_str} назад)",
        f"Ошибок за {ERROR_WINDOW_MIN} мин: {ds.recent_errors}",
    ]
    if ds.recent_error_lines:
        lines.append("Последние ошибки:")
        lines.extend(f"  · {x}" for x in ds.recent_error_lines)
    lines.append(
        "Runbook: 1С-агент → лог обмена → сеть/VPN → перезапуск по инструкции"
    )
    return "\n".join(lines)


def load_state(path: Path) -> dict:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:  # noqa: BLE001
        return {}


def save_state(path: Path, data: dict) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def main() -> int:
    ap = argparse.ArgumentParser(description="Мониторинг обмена с 1С (прототип)")
    ap.add_argument("--log", type=Path, default=DEFAULT_LOG)
    ap.add_argument("--state", type=Path, default=DEFAULT_STATE)
    ap.add_argument(
        "--now",
        help="Симулировать текущее время, ISO (например 2026-10-23T12:30:00)",
    )
    ap.add_argument(
        "--no-state",
        action="store_true",
        help="Не дедуплицировать, печатать все алерты заново",
    )
    args = ap.parse_args()

    if not args.log.exists():
        print(f"Лог не найден: {args.log}", file=sys.stderr)
        return 2

    rows = load_rows(args.log)
    now = parse_ts(args.now) if args.now else datetime.now(timezone.utc)
    state = analyze(rows, now)

    prev = {} if args.no_state else load_state(args.state)
    new_state = dict(prev)
    alerts_fired = 0

    print(f"=== Отчёт на {now.isoformat(timespec='seconds')} ===")
    for direction, ds in sorted(state.items()):
        sev = severity(ds, now)
        icon = {"OK": "✅", "WARNING": "⚠️", "CRITICAL": "🚨"}[sev]
        age_str = ""
        if ds.last_success is not None:
            age_min = (now - ds.last_success).total_seconds() / 60
            age_str = f", возраст успеха {int(age_min)} мин"
        print(
            f"{icon} {direction}: {sev}{age_str}, ошибок за окно: {ds.recent_errors}"
        )

        prev_sev = prev.get(direction, "OK")
        should_alert = sev != "OK" and sev != prev_sev
        new_state[direction] = sev
        if should_alert:
            alerts_fired += 1
            print()
            print(format_alert(direction, sev, ds, now))
            print()
    print(f"=== Итого алертов: {alerts_fired} ===")

    if not args.no_state:
        save_state(args.state, new_state)
    return 0


if __name__ == "__main__":
    sys.exit(main())