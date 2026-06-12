#!/usr/bin/env python3
"""Static site + lead form API for MAX messenger."""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from http import HTTPStatus
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
MAX_API_URL = "https://platform-api.max.ru/messages"
PHONE_RE = re.compile(r"\D")


def load_env(path: Path) -> None:
    if not path.is_file():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip())


def get_recipient_ids() -> list[int]:
    raw = os.environ.get("MAX_RECIPIENT_IDS", "")
    return [int(part.strip()) for part in raw.split(",") if part.strip()]


def normalize_phone(phone: str) -> str:
    digits = PHONE_RE.sub("", phone)
    if len(digits) == 11 and digits.startswith("8"):
        digits = "7" + digits[1:]
    if len(digits) == 10:
        digits = "7" + digits
    if len(digits) == 11 and digits.startswith("7"):
        return f"+7 ({digits[1:4]}) {digits[4:7]}-{digits[7:9]}-{digits[9:11]}"
    return phone.strip()


def build_message(name: str, phone: str, debt: str) -> str:
    debt_text = debt.strip() or "не указана"
    sent_at = datetime.now(timezone.utc).astimezone().strftime("%d.%m.%Y %H:%M:%S")
    return (
        "📩 Новая заявка с сайта «ЯПомогаю.рф - Банкротство»\n\n"
        f"👤 Имя: {name}\n"
        f"📞 Телефон: {phone}\n"
        f"💰 Сумма долга: {debt_text}\n\n"
        f"🕒 {sent_at}"
    )


def send_max_message(token: str, user_id: int, text: str) -> None:
    url = f"{MAX_API_URL}?user_id={user_id}"
    payload = json.dumps({"text": text, "notify": True}, ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=payload,
        method="POST",
        headers={
            "Authorization": token,
            "Content-Type": "application/json; charset=utf-8",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            body = response.read().decode("utf-8")
            if response.status >= 400:
                raise RuntimeError(body or f"HTTP {response.status}")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(detail or f"HTTP {exc.code}") from exc


def validate_lead(data: dict) -> tuple[str, str, str]:
    name = str(data.get("name", "")).strip()
    phone_raw = str(data.get("phone", "")).strip()
    debt = str(data.get("debt", "")).strip()
    consent = bool(data.get("consent"))

    errors: list[str] = []
    if len(name) < 2:
        errors.append("укажите имя")
    if len(PHONE_RE.sub("", phone_raw)) < 10:
        errors.append("укажите корректный телефон")
    if not consent:
        errors.append("подтвердите согласие на обработку данных")

    if errors:
        raise ValueError(", ".join(errors))

    return name, normalize_phone(phone_raw), debt


class SiteHandler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def end_headers(self) -> None:
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def do_OPTIONS(self) -> None:
        self.send_response(HTTPStatus.NO_CONTENT)
        self._send_cors_headers()
        self.end_headers()

    def do_POST(self) -> None:
        if self.path != "/api/lead":
            self.send_error(HTTPStatus.NOT_FOUND, "Not found")
            return
        self.handle_lead()

    def _send_cors_headers(self) -> None:
        self.send_header("Access-Control-Allow-Origin", self.headers.get("Origin", "*"))
        self.send_header("Access-Control-Allow-Methods", "POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def _send_json(self, status: int, payload: dict) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self._send_cors_headers()
        self.end_headers()
        self.wfile.write(body)

    def handle_lead(self) -> None:
        token = os.environ.get("MAX_BOT_TOKEN", "").strip()
        if not token:
            self._send_json(HTTPStatus.INTERNAL_SERVER_ERROR, {
                "ok": False,
                "error": "Сервер не настроен: отсутствует MAX_BOT_TOKEN.",
            })
            return

        try:
            length = int(self.headers.get("Content-Length", "0"))
            raw = self.rfile.read(length).decode("utf-8")
            data = json.loads(raw or "{}")
            name, phone, debt = validate_lead(data)
            message = build_message(name, phone, debt)
            recipients = get_recipient_ids()
            if not recipients:
                raise RuntimeError("Не указаны получатели MAX_RECIPIENT_IDS.")

            for user_id in recipients:
                send_max_message(token, user_id, message)

            self._send_json(HTTPStatus.OK, {"ok": True})
        except ValueError as exc:
            self._send_json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": str(exc)})
        except json.JSONDecodeError:
            self._send_json(HTTPStatus.BAD_REQUEST, {"ok": False, "error": "Некорректный JSON."})
        except Exception as exc:
            sys.stderr.write(f"Lead send error: {exc}\n")
            self._send_json(HTTPStatus.BAD_GATEWAY, {
                "ok": False,
                "error": "Не удалось отправить заявку в MAX. Попробуйте позже или позвоните нам.",
            })

    def log_message(self, format: str, *args) -> None:
        sys.stderr.write("%s - [%s] %s\n" % (self.address_string(), self.log_date_time_string(), format % args))


def main() -> None:
    load_env(ROOT / ".env")
    port = int(os.environ.get("PORT", "8080"))
    server = ThreadingHTTPServer(("127.0.0.1", port), SiteHandler)
    print(f"Сервер: http://127.0.0.1:{port}")
    print("Форма отправляет заявки в MAX через POST /api/lead")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nОстановлен.")
        server.server_close()


if __name__ == "__main__":
    main()
