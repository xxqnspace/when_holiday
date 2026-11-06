#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
什么时候放假啊 · VPS 版（Python 3.10 标准库实现，零第三方依赖）

原版是 Cloudflare Pages Functions + D1。本文件把后端等价移植到
Python + SQLite，接口路径、响应结构、Cookie 名、CSRF 方案、密码哈希
格式全部保持一致，因此 public/ 下的前端代码不需要任何改动。

主要差异（适配点）：
  * 存储：D1（远程 SQLite）→ 本地 SQLite 文件（WAL 模式）
  * 运行时：Workers（每请求隔离、CPU 10ms 上限）→ 常驻进程，可放心提高 PBKDF2 迭代数
  * 静态资源 + 响应头：Pages + _headers 文件 → 由本程序直接下发（见 _cache_control）
  * 时区：手工 +8 偏移 → 仍固定 +8（中国无夏令时，语义不变）
"""

import hashlib
import hmac
import ipaddress
import json
import mimetypes
import os
import re
import secrets
import sqlite3
import sys
import threading
import time
import traceback
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, unquote, urlsplit

# ============================================================
# 常量与运行参数
# ============================================================

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STATIC_DIR = os.environ.get("STATIC_DIR") or os.path.join(BASE_DIR, "static")
DATA_DIR = os.path.dirname(os.environ.get("DB_PATH") or os.path.join(BASE_DIR, "data", "holiday.db"))
DB_PATH = os.environ.get("DB_PATH") or os.path.join(BASE_DIR, "data", "holiday.db")

# 背景图缓存：每天首次访问时从上游拉一张，落盘后当天的请求都走本地
BG_DIR = os.path.join(DATA_DIR, "bg")
BG_FILE = os.path.join(BG_DIR, "current")          # 固定文件名，新图直接覆盖旧图
BG_META = os.path.join(BG_DIR, "meta.json")        # 记录日期、来源、类型、ETag
BG_MAX_BYTES = 16 * 1024 * 1024                    # 上游异常时别把磁盘写满
BG_INFO_MAX_BYTES = 256 * 1024                     # 壁纸信息 JSON 不该这么大，防异常响应
# 一张真实壁纸不可能这么小。低于这个值基本是错误页 / 占位响应（实测遇到上游返回 2 字节 "OK"）
BG_MIN_BYTES = 10 * 1024
# 上游当天还没换图（拉回来和昨天一字节不差）时，不锁死一整天，隔一段时间再试
BG_RETRY_MINUTES = 30
BG_RETRY_MAX = 8
# 常见的图片文件头：光看 Content-Type 不可靠，必须按字节验证
BG_MAGIC = (
    (b"\xff\xd8\xff", "image/jpeg"),
    (b"\x89PNG\r\n\x1a\n", "image/png"),
    (b"GIF87a", "image/gif"),
    (b"GIF89a", "image/gif"),
    (b"BM", "image/bmp"),
)
BG_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
         "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")

HOST = os.environ.get("HOST", "0.0.0.0")
PORT = int(os.environ.get("PORT", "28417"))

# PBKDF2 迭代数。原版因 Workers 免费版 10ms CPU 限制只能取 3000；
# 常驻进程没有这个限制，默认提高到 300000（OWASP 建议 600000，可按机器性能调）。
PBKDF2_ITERATIONS = int(os.environ.get("PBKDF2_ITERATIONS", "300000"))

# 会话保持天数
SESSION_DAYS = int(os.environ.get("SESSION_DAYS", "30"))

SESSION_COOKIE = "holiday_session"
CSRF_COOKIE = "holiday_csrf"

SHANGHAI = timezone(timedelta(hours=8))

# 兜底配置：settings 表缺某个键时用这里的值补齐（对应 config.js）
DEFAULT_SETTINGS = {
    "start_date": "2026-03-05 06:30:00",
    "end_date": "2026-07-03 17:00:00",
    "holidays": ["2026-04-06", "2026-05-01", "2026-05-04", "2026-05-05", "2026-06-19"],
    "workdays": ["2026-05-09"],
    "mode": "online",
    "bg_type": "solid",
    "bg_color": "#f7f9fc",
    # 前端实际加载的地址：指向本站的缓存接口，不再直连第三方
    "bg_image_url": "/bg",
    # 真正的图片来源（第三方壁纸接口），由服务端每天拉取一次
    "bg_upstream_url": "https://wall.1472588.xyz/today.jpg",
    # 同一站点的图片信息接口（返回当日壁纸的标题与版权说明）
    "bg_info_url": "https://wall.1472588.xyz/info.json",
    # 1 = 每天首次访问首页时，把壁纸标题/版权以顶部公告弹出；0 = 关闭
    "bg_info_announce": "1",
    # 弹出频率：0 = 每天只弹一次（同一张图不重复）；1 = 每次打开首页都弹
    "bg_info_always": "0",
    # 公告正文怎么拼：auto=标题+版权 / title=仅标题 / custom=自定义模板
    "bg_info_mode": "auto",
    # custom 模式的模板，支持 {title} 与 {copyright} 两个占位符
    "bg_info_template": "**今日壁纸：{title}**\n{copyright}",
    # 公告停留秒数（进度条走完自动收起）
    "bg_info_seconds": "9",
    "bg_image_opacity": "0.3",
    "glass_opacity": "0.7",
    "primary_color": "#5c6bc0",
    "font_color": "#333333",
    "card_bg": "#ffffff",
    "page_bg": "#f7f9fc",
    "progress_bar_start": "#7ed957",
    "progress_bar_end": "#2e8b57",
    "progress_percent_color": "#5c6bc0",
    "title": "什么时候放假啊o(╥﹏╥)o",
    "announcement_auto_close": "5",
}

# 首次初始化写入数据库的配置（沿用 migrations/0001_init.sql 里的初始值）
INITIAL_SETTINGS = {
    "start_date": "2026-09-01 06:30:00",
    "end_date": "2027-01-22 17:00:00",
    "holidays": [
        "2026-10-01", "2026-10-02", "2026-10-05", "2026-10-06",
        "2026-10-07", "2026-11-16", "2026-11-17", "2026-11-18", "2026-09-25",
    ],
    "workdays": ["2026-10-10", "2026-09-20"],
    "mode": "online",
    "bg_type": "image",
    "bg_color": "#f7f9fc",
    "bg_image_url": "/bg",
    "bg_upstream_url": "https://wall.1472588.xyz/today.jpg",
    "bg_info_url": "https://wall.1472588.xyz/info.json",
    "bg_info_announce": "1",
    "bg_info_always": "0",
    "bg_info_mode": "auto",
    "bg_info_template": "**今日壁纸：{title}**\n{copyright}",
    "bg_info_seconds": "9",
    "bg_image_opacity": "1",
    "glass_opacity": "0.35",
    "primary_color": "#5c6bc0",
    "font_color": "#333333",
    "card_bg": "#ffffff",
    "page_bg": "#f7f9fc",
    "progress_bar_start": "#076cfb",
    "progress_bar_end": "#076cfb",
    "progress_percent_color": "#5c6dc0",
    "title": "什么时候放假啊o(╥﹏╥)o",
    "announcement_auto_close": "5",
}

SETTINGS_ALLOWED_KEYS = set(DEFAULT_SETTINGS.keys())
JSON_SETTINGS_KEYS = {"holidays", "workdays"}
VALID_ROLES = ("admin", "user")

# 壁纸信息公告：合法的正文拼装模式，以及停留秒数的允许区间
BG_INFO_MODES = ("auto", "title", "custom")
BG_INFO_SECONDS_MIN, BG_INFO_SECONDS_MAX = 3, 60


def normalize_setting(key, value):
    """把少数配置项收敛到合法范围，避免前端传脏值把首页写崩。"""
    if key == "bg_info_mode":
        return value if value in BG_INFO_MODES else "auto"
    if key == "bg_info_seconds":
        try:
            seconds = int(float(value))
        except (TypeError, ValueError):
            seconds = 9
        return str(max(BG_INFO_SECONDS_MIN, min(BG_INFO_SECONDS_MAX, seconds)))
    if key in ("bg_info_announce", "bg_info_always"):
        # 这两个都是开关：默认开（announce）/ 默认关（always），只认 0/1 语义
        default = "1" if key == "bg_info_announce" else "0"
        raw = str(value).strip()
        if raw in ("0", "false", "False", ""):
            return "0"
        if raw in ("1", "true", "True", "on"):
            return "1"
        return default
    if key == "bg_info_template":
        return str(value).replace("\r\n", "\n")[:500]
    if key in ("bg_info_url", "bg_upstream_url"):
        return str(value).strip()[:500]
    return value

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  username   TEXT    NOT NULL UNIQUE,
  password   TEXT    NOT NULL,
  role       TEXT    NOT NULL DEFAULT 'user',
  created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS announcements (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  content    TEXT    NOT NULL DEFAULT '',
  show_from  TEXT,
  show_until TEXT,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ann_created ON announcements (created_at);

CREATE TABLE IF NOT EXISTS sessions (
  token      TEXT    PRIMARY KEY,
  user_id    INTEGER NOT NULL,
  username   TEXT    NOT NULL,
  role       TEXT    NOT NULL,
  created_at TEXT    NOT NULL,
  expires_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires_at);
"""

_db_lock = threading.Lock()
_seeded = False


# ============================================================
# 工具函数
# ============================================================

def now_shanghai(dt: datetime = None) -> str:
    """返回上海时区的 'YYYY-MM-DD HH:MM:SS'，与原版 / PHP 输出格式一致。"""
    if dt is None:
        dt = datetime.now(SHANGHAI)
    elif dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(SHANGHAI).strftime("%Y-%m-%d %H:%M:%S")


def parse_cookies(header: str) -> dict:
    out = {}
    for pair in (header or "").split(";"):
        idx = pair.find("=")
        if idx < 0:
            continue
        key = pair[:idx].strip()
        if not key:
            continue
        out[key] = unquote(pair[idx + 1:].strip())
    return out


def build_cookie(name, value, is_https, max_age=None, http_only=False, same_site="Lax") -> str:
    parts = ["%s=%s" % (name, value), "Path=/"]
    if max_age is not None:
        parts.append("Max-Age=%d" % max_age)
    if http_only:
        parts.append("HttpOnly")
    if is_https:
        parts.append("Secure")
    parts.append("SameSite=%s" % same_site)
    return "; ".join(parts)


def random_hex(nbytes: int = 32) -> str:
    return secrets.token_hex(nbytes)


# ============================================================
# 数据库
# ============================================================

def connect() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH, timeout=10.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def init_db() -> None:
    os.makedirs(DATA_DIR, exist_ok=True)
    conn = connect()
    try:
        conn.execute("PRAGMA journal_mode = WAL")
        conn.execute("PRAGMA synchronous = NORMAL")
        conn.executescript(SCHEMA)
        conn.commit()
    finally:
        conn.close()


def to_db_value(key, value) -> str:
    if key in JSON_SETTINGS_KEYS:
        return json.dumps(value, ensure_ascii=False)
    return str(value)


def ensure_seed(conn: sqlite3.Connection) -> bool:
    """首次运行：建默认管理员并补齐配置键。用户表非空时只做一次 COUNT。"""
    global _seeded
    if _seeded:
        return False

    n = conn.execute("SELECT COUNT(*) AS n FROM users").fetchone()["n"]
    if n > 0:
        _seeded = True
        return False

    admin_password = os.environ.get("ADMIN_PASSWORD") or "admin123"
    conn.execute(
        "INSERT OR IGNORE INTO users (username, password, role, created_at) VALUES (?, ?, ?, ?)",
        ("admin", hash_password(admin_password), "admin", now_shanghai()),
    )

    merged = dict(DEFAULT_SETTINGS)
    merged.update(INITIAL_SETTINGS)
    conn.executemany(
        "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)",
        [(k, to_db_value(k, v)) for k, v in merged.items()],
    )
    conn.commit()
    _seeded = True
    print("[seed] 已创建默认管理员 admin（密码来自 ADMIN_PASSWORD 环境变量）", flush=True)
    return True


# ---------- 配置 ----------

def get_settings(conn) -> dict:
    settings = dict(DEFAULT_SETTINGS)
    for row in conn.execute("SELECT key, value FROM settings"):
        key, value = row["key"], row["value"]
        if key not in SETTINGS_ALLOWED_KEYS:
            continue
        if key in JSON_SETTINGS_KEYS:
            try:
                parsed = json.loads(value)
                settings[key] = parsed if isinstance(parsed, list) else []
            except (ValueError, TypeError):
                settings[key] = []
        else:
            settings[key] = value
    return settings


def save_settings(conn, patch: dict) -> int:
    count = 0
    for key, raw in (patch or {}).items():
        if key not in SETTINGS_ALLOWED_KEYS:
            continue

        value = raw
        if key in JSON_SETTINGS_KEYS:
            if isinstance(value, str):
                try:
                    value = json.loads(value)
                except (ValueError, TypeError):
                    value = []
            if not isinstance(value, list):
                value = []
            value = [item for item in value if isinstance(item, str)]
        elif value is None:
            continue
        else:
            value = normalize_setting(key, value)

        conn.execute(
            "INSERT INTO settings (key, value) VALUES (?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, to_db_value(key, value)),
        )
        count += 1
    conn.commit()
    return count


# ---------- 用户 ----------

def find_user_by_username(conn, username):
    return conn.execute("SELECT * FROM users WHERE username = ?", (username,)).fetchone()


def find_user_by_id(conn, user_id):
    return conn.execute("SELECT * FROM users WHERE id = ?", (user_id,)).fetchone()


def list_users(conn):
    rows = conn.execute("SELECT id, username, role, created_at FROM users ORDER BY id ASC")
    return [dict(r) for r in rows]


def create_user(conn, username, password, role):
    conn.execute(
        "INSERT INTO users (username, password, role, created_at) VALUES (?, ?, ?, ?)",
        (username, hash_password(password), role, now_shanghai()),
    )
    conn.commit()


def update_user(conn, user_id, role, new_password=None):
    if new_password:
        conn.execute(
            "UPDATE users SET role = ?, password = ? WHERE id = ?",
            (role, hash_password(new_password), user_id),
        )
    else:
        conn.execute("UPDATE users SET role = ? WHERE id = ?", (role, user_id))
    conn.commit()


def delete_user(conn, user_id):
    conn.execute("DELETE FROM users WHERE id = ?", (user_id,))
    conn.execute("DELETE FROM sessions WHERE user_id = ?", (user_id,))
    conn.commit()


# ---------- 公告 ----------

def list_announcements(conn):
    rows = conn.execute(
        "SELECT * FROM announcements ORDER BY created_at DESC, id DESC")
    return [dict(r) for r in rows]


def get_active_announcement(conn):
    now = now_shanghai()
    row = conn.execute(
        """SELECT * FROM announcements
            WHERE is_active = 1
              AND (show_from  IS NULL OR show_from  = '' OR show_from  <= ?)
              AND (show_until IS NULL OR show_until = '' OR show_until >= ?)
            ORDER BY created_at DESC, id DESC
            LIMIT 1""",
        (now, now),
    ).fetchone()
    return dict(row) if row else None


def save_announcement(conn, ann_id, content, show_from, show_until, is_active):
    if ann_id:
        conn.execute(
            "UPDATE announcements SET content = ?, show_from = ?, show_until = ?, is_active = ? WHERE id = ?",
            (content, show_from, show_until, is_active, ann_id),
        )
        conn.commit()
        return ann_id

    cur = conn.execute(
        "INSERT INTO announcements (content, show_from, show_until, is_active, created_at) "
        "VALUES (?, ?, ?, ?, ?)",
        (content, show_from, show_until, is_active, now_shanghai()),
    )
    conn.commit()
    return cur.lastrowid


def delete_announcement(conn, ann_id):
    conn.execute("DELETE FROM announcements WHERE id = ?", (ann_id,))
    conn.commit()


def migrate_settings(conn) -> None:
    """老版本把第三方壁纸地址直接写在 bg_image_url 里，由浏览器去拉。

    这里一次性迁移：上游地址搬到 bg_upstream_url，bg_image_url 改为本站的 /bg，
    之后所有访问都走服务端缓存。
    """
    row = conn.execute("SELECT value FROM settings WHERE key = 'bg_image_url'").fetchone()
    if not row:
        return
    value = (row["value"] or "").strip()
    if not re.match(r"^https?://", value, re.I):
        return

    existing = conn.execute("SELECT value FROM settings WHERE key = 'bg_upstream_url'").fetchone()
    if not existing or not (existing["value"] or "").strip():
        conn.execute(
            "INSERT INTO settings (key, value) VALUES ('bg_upstream_url', ?) "
            "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (value,),
        )
    conn.execute("UPDATE settings SET value = '/bg' WHERE key = 'bg_image_url'")
    conn.commit()
    print("[migrate] bg_image_url -> /bg，上游地址保存为 %s" % value, flush=True)


# ============================================================
# 背景图每日缓存
# ============================================================

_bg_lock = threading.Lock()


def today_shanghai() -> str:
    return datetime.now(SHANGHAI).strftime("%Y-%m-%d")


def read_bg_meta() -> dict:
    try:
        with open(BG_META, encoding="utf-8") as fh:
            data = json.load(fh)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError):
        return {}


def bg_version(meta: dict = None) -> str:
    """当前这张图的内容版本号。

    前端会把它拼到 /bg?v=... 上：图一换，URL 就变，手机/PWA 里那些
    已经缓存住的旧图（甚至坏图）立刻失效，不用等缓存过期。
    """
    meta = read_bg_meta() if meta is None else meta
    etag = (meta.get("etag") or "").strip()
    if etag:
        return etag.strip('"').split("-")[-1]
    return ""


def bg_retry_due(meta: dict) -> bool:
    """当天已缓存，但在等上游换图：到点了就允许再拉一次。"""
    if meta.get("date") != today_shanghai():
        return False
    if int(meta.get("retry_count") or 0) >= BG_RETRY_MAX:
        return False
    retry_at = (meta.get("retry_at") or "").strip()
    return bool(retry_at) and now_shanghai() >= retry_at


def bg_cache_usable(meta: dict) -> bool:
    """这次请求能不能直接用本地缓存（不发外部请求）。"""
    if meta.get("date") != today_shanghai() or not os.path.exists(BG_FILE):
        return False
    return not bg_retry_due(meta)


def bg_status() -> dict:
    """给 /api/bg_status 用的状态快照。"""
    meta = read_bg_meta()
    exists = os.path.exists(BG_FILE)
    return {
        "cached": bool(exists and meta.get("date")),
        "refreshing": bg_refreshing(),
        "version": bg_version(meta),
        "date": meta.get("date"),
        "is_today": meta.get("date") == today_shanghai(),
        "fetched_at": meta.get("fetched_at"),
        "upstream": meta.get("upstream"),
        "content_type": meta.get("content_type"),
        "bytes": os.path.getsize(BG_FILE) if exists else 0,
        # 内容版本号：前端拼到 /bg?v= 上，换了图就能立刻破缓存
        "version": bg_version(meta),
        # 上游还没换图时的自动重试计划
        "retry_at": meta.get("retry_at") or "",
        "retry_count": int(meta.get("retry_count") or 0),
        "awaiting_update": bool(meta.get("retry_at")
                                and int(meta.get("retry_count") or 0) < BG_RETRY_MAX),
        # 当日壁纸的说明信息（来自 bg_info_url，抓不到就是空）
        "title": meta.get("info_title") or "",
        "copyright": meta.get("info_copyright") or "",
        "info_date": meta.get("info_date") or "",
        "info_at": meta.get("info_at") or "",
    }


def upstream_url(conn) -> str:
    # 必须走 get_settings：表里可能还没有这一行，只有默认值里有
    value = str(get_settings(conn).get("bg_upstream_url") or "").strip()
    return value if re.match(r"^https?://", value, re.I) else ""


def info_url(conn) -> str:
    value = str(get_settings(conn).get("bg_info_url") or "").strip()
    return value if re.match(r"^https?://", value, re.I) else ""


def write_bg_meta(meta: dict) -> None:
    """原子写 meta.json，避免半个文件被读到。"""
    try:
        os.makedirs(BG_DIR, exist_ok=True)
        tmp = BG_META + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(meta, fh, ensure_ascii=False)
        os.replace(tmp, BG_META)
    except OSError as exc:
        print("[bg] 写 meta 失败：%s" % exc, flush=True)


def cleanup_bg_dir() -> int:
    """清掉缓存目录里除了 current / meta.json 之外的所有文件。

    正常情况下这里永远只有这两个文件（新图直接 os.replace 覆盖旧图，磁盘占用恒定
    等于一张图的大小）。这个清扫是给意外情况兜底的：写文件中途断电/被杀会留下
    current.tmp，老版本按扩展名分文件时也可能有残留。
    返回清掉的文件数。
    """
    if not os.path.isdir(BG_DIR):
        return 0
    removed = 0
    for name in os.listdir(BG_DIR):
        if name in ("current", "meta.json"):
            continue
        try:
            os.remove(os.path.join(BG_DIR, name))
            removed += 1
        except OSError:
            pass
    if removed:
        print("[bg] 清理缓存残留 %d 个文件" % removed, flush=True)
    return removed


def render_bg_info_content(settings, title: str, copy_text: str) -> str:
    """按后台配置把标题/版权拼成公告正文（Markdown 片段）。

    auto   → 标题加粗 + 版权独占一行（默认）
    title  → 只显示标题
    custom → 用后台模板，支持 {title} / {copyright} 占位符
    """
    title = title or ""
    copy_text = copy_text or ""
    mode = str((settings or {}).get("bg_info_mode") or "auto")
    if mode not in BG_INFO_MODES:
        mode = "auto"

    if mode == "title":
        return "**今日壁纸：%s**" % (title or "每日壁纸")

    if mode == "custom":
        template_ = str((settings or {}).get("bg_info_template") or "").replace("\r\n", "\n")
        body = template_.replace("{title}", title or "每日壁纸").replace("{copyright}", copy_text)
        if body.strip():
            return body.strip()
        # 模板被清空时回落到 auto，别让公告凭空消失

    text = "**今日壁纸：%s**" % (title or "每日壁纸")
    if copy_text:
        text += "\n" + copy_text
    return text


def clean_text(value, limit: int = 200) -> str:
    """把上游文本压成单行并截断，避免换行/超长字符串撑破公告条。"""
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    return text[:limit]


def fetch_bg_info(url: str) -> dict:
    """拉取壁纸信息（title / copyright），返回可直接并进 meta 的字段。"""
    req = urllib.request.Request(url, headers={
        "User-Agent": BG_UA,
        "Accept": "application/json,text/plain,*/*;q=0.8",
        "Accept-Language": "zh-CN,zh;q=0.9",
    })
    with urllib.request.urlopen(req, timeout=20) as resp:
        raw = resp.read(BG_INFO_MAX_BYTES + 1)
    if len(raw) > BG_INFO_MAX_BYTES:
        raise ValueError("info.json 超过 %d 字节上限" % BG_INFO_MAX_BYTES)
    data = json.loads(raw.decode("utf-8", "ignore"))
    if not isinstance(data, dict):
        raise ValueError("info.json 不是 JSON 对象")

    title = clean_text(data.get("title"), 120)
    copyright_ = clean_text(data.get("copyright"), 300)
    if not title and not copyright_:
        raise ValueError("info.json 里没有 title / copyright")
    return {
        "info_title": title,
        "info_copyright": copyright_,
        "info_date": clean_text(data.get("startdate"), 16),
        "info_at": now_shanghai(),
    }


def sniff_image_type(data: bytes) -> str:
    """按文件头判断图片类型；不是已知图片就返回空串。"""
    for magic, mime in BG_MAGIC:
        if data.startswith(magic):
            return mime
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    if data[4:8] == b"ftyp" and b"avif" in data[8:24]:
        return "image/avif"
    return ""


def fetch_upstream(url: str):
    """拉取上游图片，返回 (bytes, content_type)。

    只信文件头，不信 Content-Type —— 上游曾经用 image/jpeg 返回过 2 字节的 "OK"，
    那种东西存下来就是一张坏图，页面上直接空白。
    """
    req = urllib.request.Request(url, headers={
        "User-Agent": BG_UA,
        "Accept": "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "Accept-Language": "zh-CN,zh;q=0.9",
    })
    with urllib.request.urlopen(req, timeout=25) as resp:
        declared = (resp.headers.get("Content-Type") or "").split(";")[0].strip().lower()
        data = resp.read(BG_MAX_BYTES + 1)

    if not data:
        raise ValueError("上游返回空内容")
    if len(data) > BG_MAX_BYTES:
        raise ValueError("图片超过 %d 字节上限" % BG_MAX_BYTES)
    if len(data) < BG_MIN_BYTES:
        raise ValueError("上游只返回 %d 字节（Content-Type=%s），不是有效图片：%r"
                         % (len(data), declared or "-", data[:24]))

    sniffed = sniff_image_type(data)
    if not sniffed:
        raise ValueError("上游内容不是图片（前 8 字节：%s）" % data[:8].hex())
    return data, sniffed


# 后台刷新状态：同一时刻只允许一个刷新线程，失败后冷却，避免上游异常时被反复打
_bg_refresh_lock = threading.Lock()
_bg_refresh_running = False
_bg_last_attempt = 0.0
# 自动刷新失败后的冷却秒数（默认 10 分钟）；手动刷新不受此限制
BG_AUTO_COOLDOWN = int(os.environ.get("BG_AUTO_COOLDOWN", "600"))


def bg_refreshing() -> bool:
    """是否正在后台换图（供 /api/bg_version 与壁纸公告接口判断）。"""
    with _bg_refresh_lock:
        return _bg_refresh_running


def schedule_bg_refresh() -> bool:
    """安排一次后台回源；已在刷新或处于失败冷却期则跳过。"""
    global _bg_refresh_running, _bg_last_attempt
    with _bg_refresh_lock:
        if _bg_refresh_running:
            return False
        now = time.monotonic()
        if _bg_last_attempt and now - _bg_last_attempt < BG_AUTO_COOLDOWN:
            return False
        _bg_last_attempt = now
        _bg_refresh_running = True

    def runner():
        global _bg_refresh_running
        try:
            _refresh_bg_locked(force=False)
        except Exception as exc:
            print("[bg] 后台刷新异常：%s: %s" % (type(exc).__name__, exc), flush=True)
        finally:
            with _bg_refresh_lock:
                _bg_refresh_running = False

    threading.Thread(target=runner, name="bg-refresh", daemon=True).start()
    return True


def ensure_bg_fresh(force: bool = False) -> dict:
    """保证背景图可用，且**不让请求线程等上游**。

    - force=True（管理后台手动刷新）：同步回源，保留成功/失败提示。
    - 当天缓存可用：直接返回。
    - 缓存过期但本地有旧图：立刻用旧图（stale-while-revalidate），后台线程换今天的图。
    - 连旧图都没有：同样交给后台，请求立即返回，前端轮询到新版本后自动补图。

    以前这里在 /api/bg_info 的 _db_lock 内同步跑 25s 拉图 + 20s 拉信息，
    每天第一次打开会把所有接口一起堵住；现在网络请求一律不占请求线程。
    """
    if force:
        return _refresh_bg_locked(force=True)

    meta = read_bg_meta()
    if bg_cache_usable(meta):
        return dict(meta, _ok=True)

    schedule_bg_refresh()
    if os.path.exists(BG_FILE):
        return dict(meta, _ok=True, _stale=True)
    return {}


def _refresh_bg_locked(force: bool = False) -> dict:
    """真正回源刷新（原 ensure_bg_fresh 主体），由 _bg_lock 串行化。

    返回值里的标记：
      `_ok`    这次是否真的拿到了可用的图
      `_error` 失败原因（上游坏了 / 内容不是图片 / 没配地址）
      `_same`  上游给回来的图和缓存的完全一致（基本等于"今天的图还没更新"）
    """
    with _bg_lock:
        # 等锁期间可能已经被别的请求刷新过
        meta = read_bg_meta()
        if not force and bg_cache_usable(meta):
            return dict(meta, _ok=True)

        has_old = os.path.exists(BG_FILE)
        prev_date = meta.get("date")

        conn = connect()
        try:
            url = upstream_url(conn)
            meta_info_url = info_url(conn)
        finally:
            conn.close()

        if not url:
            if has_old:
                return dict(meta, _ok=False, _error="未配置图片源地址")
            return {}

        started = time.time()
        try:
            data, ctype = fetch_upstream(url)
        except Exception as exc:
            print("[bg] 拉取失败：%s: %s" % (type(exc).__name__, exc), flush=True)
            if has_old:
                return dict(meta, _ok=False, _error="%s: %s" % (type(exc).__name__, exc))
            return {}

        # 和现有文件比一次字节：完全一样通常意味着上游还没换今天的图
        same_as_old = False
        if has_old:
            try:
                with open(BG_FILE, "rb") as fh:
                    same_as_old = hashlib.sha1(fh.read()).digest() == hashlib.sha1(data).digest()
            except OSError:
                same_as_old = False

        os.makedirs(BG_DIR, exist_ok=True)
        tmp = BG_FILE + ".tmp"
        with open(tmp, "wb") as fh:
            fh.write(data)
        os.replace(tmp, BG_FILE)  # 原子替换，旧图同时被覆盖

        # 清理历史遗留文件（老版本按扩展名分文件时的残留、写文件中途留下的 .tmp）
        cleanup_bg_dir()

        # 顺带抓当天的壁纸说明（标题 / 版权）。抓不到不影响出图，只是不弹公告。
        info = {}
        if meta_info_url:
            try:
                info = fetch_bg_info(meta_info_url)
            except Exception as exc:
                print("[bg] 壁纸信息拉取失败：%s: %s" % (type(exc).__name__, exc), flush=True)

        new_meta = {
            "date": today_shanghai(),
            "fetched_at": now_shanghai(),
            "upstream": url,
            "content_type": ctype,
            "bytes": len(data),
            "etag": '"%s-%s"' % (today_shanghai(), hashlib.sha1(data).hexdigest()[:16]),
        }
        new_meta.update(info)

        # 上游当天还没换图时不能锁死一整天，否则早上 7 点拉到昨天的图，
        # 接下来 17 个小时都是它。这里排一次重试，30 分钟后再看。
        if same_as_old and (prev_date != today_shanghai() or meta.get("retry_at")):
            attempts = int(meta.get("retry_count") or 0) if prev_date == today_shanghai() else 0
            if attempts < BG_RETRY_MAX:
                new_meta["retry_count"] = attempts + 1
                new_meta["retry_at"] = now_shanghai(
                    datetime.now(SHANGHAI) + timedelta(minutes=BG_RETRY_MINUTES))
                print("[bg] 上游返回的仍是上一张图，%d 分钟后重试（第 %d/%d 次）"
                      % (BG_RETRY_MINUTES, attempts + 1, BG_RETRY_MAX), flush=True)

        write_bg_meta(new_meta)
        print("[bg] 已缓存 %s（%s，%d KB，%.2fs）"
              % (new_meta["date"], ctype, len(data) // 1024, time.time() - started), flush=True)
        return dict(new_meta, _ok=True, _same=same_as_old)


# ============================================================
# 认证
# ============================================================

_HASH_RE = re.compile(r"^pbkdf2\$(?P<iter>\d+)\$(?P<salt>[0-9a-f]+)\$(?P<digest>[0-9a-f]+)$", re.I)


def hash_password(password: str, iterations: int = None) -> str:
    """pbkdf2$<迭代数>$<saltHex>$<hashHex>，与原版存储格式完全一致。"""
    iterations = iterations or PBKDF2_ITERATIONS
    salt = os.urandom(16)
    digest = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations, dklen=32)
    return "pbkdf2$%d$%s$%s" % (iterations, salt.hex(), digest.hex())


def verify_password(password: str, stored: str) -> bool:
    m = _HASH_RE.match(str(stored or ""))
    if not m:
        return False
    try:
        iterations = int(m.group("iter"))
        salt = bytes.fromhex(m.group("salt"))
        expected = bytes.fromhex(m.group("digest"))
    except ValueError:
        return False
    if iterations <= 0 or not salt or not expected:
        return False
    actual = hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, iterations, dklen=len(expected))
    return hmac.compare_digest(actual, expected)


def create_session(conn, user):
    token = random_hex(32)
    now = datetime.now(SHANGHAI)
    # 顺手清掉过期会话：sessions 表原来只会读一条删一条，没被读过的过期行会一直留着
    conn.execute("DELETE FROM sessions WHERE expires_at <= ?", (now_shanghai(now),))
    conn.execute(
        "INSERT INTO sessions (token, user_id, username, role, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)",
        (
            token, user["id"], user["username"], user["role"],
            now_shanghai(now),
            now_shanghai(now + timedelta(days=SESSION_DAYS)),
        ),
    )
    conn.commit()
    return token, SESSION_DAYS * 86400


def delete_session(conn, token):
    if not token:
        return
    conn.execute("DELETE FROM sessions WHERE token = ?", (token,))
    conn.commit()


def read_session(conn, token):
    if not token:
        return None
    row = conn.execute("SELECT * FROM sessions WHERE token = ?", (token,)).fetchone()
    if not row:
        return None
    if row["expires_at"] <= now_shanghai():
        delete_session(conn, token)
        return None
    return row


def verify_csrf(cookies: dict, header_token: str) -> bool:
    cookie_token = cookies.get(CSRF_COOKIE) or ""
    if not cookie_token or not header_token:
        return False
    return hmac.compare_digest(cookie_token.encode(), header_token.encode())


# ============================================================
# API 处理
# ============================================================

class ApiError(Exception):
    pass


def ok(data=None, message=""):
    return {"success": True, "message": message, "data": data}


def fail(message="", data=None):
    return {"success": False, "message": message, "data": data}


def json_body(payload, content_type: str) -> dict:
    """只在 Content-Type 为 JSON 时解析请求体，与原版 readJsonBody 一致。"""
    if "application/json" not in (content_type or ""):
        return {}
    try:
        text = payload.decode("utf-8") if payload else ""
        if not text:
            return {}
        parsed = json.loads(text)
        return parsed if isinstance(parsed, dict) else {}
    except (ValueError, UnicodeDecodeError):
        return {}


class Ctx:
    def __init__(self, conn, method, action, cookies, csrf_token, body, is_https):
        self.conn = conn
        self.method = method
        self.action = action
        self.cookies = cookies
        self.csrf_token = csrf_token
        self.body = body
        self.is_https = is_https
        self.session = None
        self.set_cookies = []

    def cookie(self, name, value, max_age=None, http_only=False):
        self.set_cookies.append(
            build_cookie(name, value, self.is_https, max_age=max_age, http_only=http_only)
        )


def require_login(ctx) -> dict:
    session = read_session(ctx.conn, ctx.cookies.get(SESSION_COOKIE))
    if not session:
        return fail("请先登录")
    ctx.session = session
    return None


def require_admin(ctx) -> dict:
    err = require_login(ctx)
    if err:
        return err
    if ctx.session["role"] != "admin":
        return fail("需要管理员权限")
    return None


def require_csrf(ctx) -> dict:
    if not verify_csrf(ctx.cookies, ctx.header_csrf):
        return fail("安全校验失败，请刷新页面重试")
    return None


def route(ctx, header_csrf: str) -> dict:
    action = ctx.action
    is_post = ctx.method == "POST"
    ctx.header_csrf = header_csrf

    def post_only():
        return fail("操作 %s 需要使用 POST 请求" % action)

    # ---------- 认证 ----------
    if action == "session":
        session = read_session(ctx.conn, ctx.cookies.get(SESSION_COOKIE))
        return ok({
            "logged_in": bool(session),
            "user": ({"id": session["user_id"], "username": session["username"],
                      "role": session["role"]} if session else None),
            "csrf_token": ctx.csrf_token,
        })

    if action == "login":
        if not is_post:
            return post_only()
        err = require_csrf(ctx)
        if err:
            return err
        username = str(ctx.body.get("username") or "").strip()
        password = str(ctx.body.get("password") or "")
        if not username or not password:
            return fail("用户名和密码不能为空")
        user = find_user_by_username(ctx.conn, username)
        if not user or not verify_password(password, user["password"]):
            return fail("用户名或密码错误")
        token, max_age = create_session(ctx.conn, user)
        ctx.cookie(SESSION_COOKIE, token, max_age=max_age, http_only=True)
        return ok({"id": user["id"], "username": user["username"], "role": user["role"]}, "登录成功")

    if action == "logout":
        if not is_post:
            return post_only()
        delete_session(ctx.conn, ctx.cookies.get(SESSION_COOKIE))
        ctx.cookie(SESSION_COOKIE, "", max_age=0, http_only=True)
        return ok(None, "已退出登录")

    if action == "change_password":
        if not is_post:
            return post_only()
        err = require_login(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        old_password = str(ctx.body.get("old_password") or "")
        new_password = str(ctx.body.get("new_password") or "")
        if len(new_password) < 6:
            return fail("新密码至少6位")
        user = find_user_by_id(ctx.conn, ctx.session["user_id"])
        if not user or not verify_password(old_password, user["password"]):
            return fail("原密码错误")
        update_user(ctx.conn, user["id"], user["role"], new_password)
        return ok(None, "密码修改成功")

    # ---------- 用户管理 ----------
    if action == "get_users":
        err = require_admin(ctx)
        if err:
            return err
        return ok(list_users(ctx.conn))

    if action == "create_user":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        username = str(ctx.body.get("username") or "").strip()
        password = str(ctx.body.get("password") or "")
        role = str(ctx.body.get("role") or "user")
        if not username or len(password) < 6:
            return fail("用户名不能为空，密码至少6位")
        if role not in VALID_ROLES:
            return fail("角色无效")
        if find_user_by_username(ctx.conn, username):
            return fail("用户名已存在")
        try:
            create_user(ctx.conn, username, password, role)
        except sqlite3.IntegrityError:
            return fail("用户名已存在")
        return ok(None, "用户创建成功")

    if action == "update_user":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        user_id = int(ctx.body.get("user_id") or 0)
        role = str(ctx.body.get("role") or "")
        new_password = str(ctx.body.get("new_password") or "")
        if user_id <= 0:
            return fail("用户ID无效")
        if role not in VALID_ROLES:
            return fail("角色无效")
        if new_password and len(new_password) < 6:
            return fail("新密码至少6位")
        if not find_user_by_id(ctx.conn, user_id):
            return fail("用户不存在")
        update_user(ctx.conn, user_id, role, new_password or None)
        return ok(None, "用户更新成功")

    if action == "delete_user":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        user_id = int(ctx.body.get("user_id") or 0)
        if user_id <= 0:
            return fail("用户ID无效")
        if user_id == ctx.session["user_id"]:
            return fail("不能删除自己的账号")
        delete_user(ctx.conn, user_id)
        return ok(None, "用户已删除")

    # ---------- 配置 ----------
    if action == "get_settings":
        settings = get_settings(ctx.conn)
        # 图片内容版本号：前端拿它给 /bg 加 ?v=，换图后客户端缓存自动失效
        settings["bg_version"] = bg_version()
        return ok(settings)

    if action == "save_settings":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        settings = ctx.body.get("settings")
        if isinstance(settings, str):
            try:
                settings = json.loads(settings)
            except (ValueError, TypeError):
                settings = None
        if not isinstance(settings, dict):
            return fail("无效的配置数据")
        save_settings(ctx.conn, settings)
        return ok(None, "配置保存成功")

    if action == "get_mode":
        settings = get_settings(ctx.conn)
        return ok({"mode": settings.get("mode") or "online"})

    # ---------- 背景图缓存 ----------
    if action == "bg_version":
        # 轻量探针：只读 meta 文件，不碰数据库、不发网络请求，
        # 供前端判断后台换图是否完成（每天首次打开不会被它拖慢）。
        meta = read_bg_meta()
        return ok({
            "version": bg_version(meta),
            "date": meta.get("date") or "",
            "refreshing": bg_refreshing(),
            "has_image": os.path.exists(BG_FILE),
        })

    if action == "bg_info":
        # 首页用：当天壁纸的标题与版权说明。只有展示用文案，不需要登录。
        settings = get_settings(ctx.conn)
        if (settings.get("bg_type") or "solid") != "image" \
                or str(settings.get("bg_info_announce", "1")) == "0":
            return ok({"enabled": False})
        meta = ensure_bg_fresh()
        title = meta.get("info_title") or ""
        copy_text = meta.get("info_copyright") or ""
        content = render_bg_info_content(settings, title, copy_text) if (title or copy_text) else ""
        return ok({
            "enabled": bool(content),
            "refreshing": bg_refreshing(),
            "date": meta.get("date") or "",
            "title": title,
            "copyright": copy_text,
            "info_date": meta.get("info_date") or "",
            # 后台可配的两项：正文（前端直接渲染）与停留秒数
            "content": content,
            "seconds": int(settings.get("bg_info_seconds") or 9),
            "mode": settings.get("bg_info_mode") or "auto",
            # true = 每次打开首页都弹（前端跳过「已看过」判断）
            "always": str(settings.get("bg_info_always", "0")) == "1",
        })

    if action == "bg_status":
        err = require_admin(ctx)
        if err:
            return err
        status = bg_status()
        settings = get_settings(ctx.conn)
        title, copy_text = status.get("title") or "", status.get("copyright") or ""
        status["announce"] = {
            "enabled": str(settings.get("bg_info_announce", "1")) != "0",
            "always": str(settings.get("bg_info_always", "0")) == "1",
            "mode": settings.get("bg_info_mode") or "auto",
            "seconds": int(settings.get("bg_info_seconds") or 9),
            "template": settings.get("bg_info_template") or "",
            "info_url": settings.get("bg_info_url") or "",
            "content": render_bg_info_content(settings, title, copy_text) if (title or copy_text) else "",
        }
        return ok(status)

    if action == "refresh_bg_info":
        # 只重抓 info.json（标题/版权），不动已经缓存的背景图
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        url = info_url(ctx.conn)
        if not url:
            return fail("请先填写图片信息接口地址")
        try:
            info = fetch_bg_info(url)
        except Exception as exc:
            print("[bg] 手动抓取图片信息失败：%s: %s" % (type(exc).__name__, exc), flush=True)
            return fail("抓取图片信息失败：%s" % str(exc)[:120])
        meta = read_bg_meta()
        meta.update(info)
        write_bg_meta(meta)
        return ok(bg_status(), "图片信息已更新")

    if action == "refresh_bg":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        meta = ensure_bg_fresh(force=True)
        if not meta:
            return fail("刷新失败：上游拿不到图，本地也没有可用缓存")
        if not meta.get("_ok", True):
            return fail("刷新失败，已保留原背景图：%s" % (meta.get("_error") or "上游无响应"))
        if meta.get("_same"):
            if meta.get("retry_at"):
                return ok(bg_status(), "上游给的还是同一张图，已安排在 %s 自动重试"
                          % meta["retry_at"])
            return ok(bg_status(), "上游给的还是同一张图（可能今天还没更新）")
        return ok(bg_status(), "背景图已更新")

    # ---------- 公告 ----------
    if action == "get_announcement":
        return ok(get_active_announcement(ctx.conn))

    if action == "get_announcements":
        err = require_admin(ctx)
        if err:
            return err
        return ok(list_announcements(ctx.conn))

    if action == "save_announcement":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        ann_id = int(ctx.body.get("id") or 0) or None
        save_announcement(
            ctx.conn,
            ann_id,
            str(ctx.body.get("content") or ""),
            str(ctx.body["show_from"]) if ctx.body.get("show_from") else None,
            str(ctx.body["show_until"]) if ctx.body.get("show_until") else None,
            1 if int(ctx.body.get("is_active", 1) or 0) else 0,
        )
        return ok(None, "公告保存成功")

    if action == "delete_announcement":
        if not is_post:
            return post_only()
        err = require_admin(ctx)
        if err:
            return err
        err = require_csrf(ctx)
        if err:
            return err
        ann_id = int(ctx.body.get("id") or 0)
        if ann_id <= 0:
            return fail("公告ID无效")
        delete_announcement(ctx.conn, ann_id)
        return ok(None, "公告已删除")

    return fail("未知操作：" + action)


# ============================================================
# HTTP 层
# ============================================================

HTML_CACHE = "public, max-age=0, must-revalidate"
ICON_CACHE = "public, max-age=2592000"
SW_CACHE = "no-cache"


def cache_control_for(path: str) -> str:
    """等价于 Pages 的 _headers 文件。"""
    if path == "/" or path.endswith(".html"):
        return HTML_CACHE
    if path == "/sw.js":
        return SW_CACHE
    if path.startswith("/icons/"):
        return ICON_CACHE
    return "public, max-age=300"


class Handler(BaseHTTPRequestHandler):
    server_version = "HolidayVPS/4.1"
    protocol_version = "HTTP/1.1"

    # ---------- 路由 ----------

    def do_GET(self):
        self._handle("GET")

    def do_HEAD(self):
        self._handle("HEAD")

    def do_POST(self):
        self._handle("POST")

    def _handle(self, method):
        try:
            path = unquote(urlsplit(self.path).path)
            if path in ("/bg", "/bg.jpg", "/background"):
                self._handle_bg(method)
            elif path.startswith("/api/") or path == "/api":
                self._handle_api(method, path)
            else:
                self._handle_static(method, path)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception:
            traceback.print_exc()
            try:
                self._send_json(fail("服务器内部错误"), 500)
            except Exception:
                pass

    # ---------- API ----------

    def _handle_api(self, method, path):
        action = path[len("/api/"):].strip("/").split("/")[0] if path != "/api" else ""
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length > 0 else b""
        body = json_body(raw, self.headers.get("Content-Type"))

        cookies = parse_cookies(self.headers.get("Cookie"))
        csrf_token = cookies.get(CSRF_COOKIE) or ""
        new_csrf = None
        if not csrf_token:
            csrf_token = random_hex(16)
            new_csrf = csrf_token

        is_https = (self.headers.get("X-Forwarded-Proto", "").lower() == "https")

        with _db_lock:
            conn = connect()
            try:
                ensure_seed(conn)
                ctx = Ctx(conn, method, action, cookies, csrf_token, body, is_https)
                try:
                    payload = route(ctx, self.headers.get("X-CSRF-Token") or "")
                except Exception as exc:
                    traceback.print_exc()
                    payload = fail("服务器内部错误：" + str(exc))

                if new_csrf:
                    ctx.set_cookies.append(
                        build_cookie(CSRF_COOKIE, new_csrf, is_https, max_age=86400, http_only=False)
                    )
                extra = [("Set-Cookie", c) for c in ctx.set_cookies]
            finally:
                conn.close()

        self._send_json(payload, 200, extra)

    # ---------- 背景图 ----------

    def _handle_bg(self, method):
        """按天缓存的背景图：当天首个请求才回源，其余全部本地出图。"""
        meta = ensure_bg_fresh()
        if not meta:
            self._send_error(503, "背景图暂不可用")
            return

        try:
            with open(BG_FILE, "rb") as fh:
                data = fh.read()
        except OSError:
            self._send_error(503, "背景图暂不可用")
            return

        ctype = meta.get("content_type") or "image/jpeg"
        etag = meta.get("etag") or '"%s"' % hashlib.sha1(data).hexdigest()[:16]

        # 带 ?v=<当前版本> 的请求，URL 本身随内容变化，可以放心长缓存；
        # 不带、或版本过期的（老 HTML、PWA 里的旧地址）一律每次回源校验。
        # 以前统一 max-age=3600，换了图手机上还要挂一小时旧图，就是这里埋的雷。
        client_v = (parse_qs(urlsplit(self.path).query).get("v") or [""])[0].strip()
        if client_v and client_v == bg_version(meta):
            cache_control = "public, max-age=86400, immutable"
        else:
            cache_control = "no-cache, must-revalidate"

        # 浏览器带上来的 ETag 一致就直接 304，省掉一次图片传输
        if (self.headers.get("If-None-Match") or "").strip() == etag:
            self.send_response(304)
            self.send_header("ETag", etag)
            self.send_header("Cache-Control", cache_control)
            self.end_headers()
            return

        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", cache_control)
        self.send_header("ETag", etag)
        self.send_header("X-Bg-Date", meta.get("date") or "")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        if method != "HEAD":
            self.wfile.write(data)

    # ---------- 静态资源 ----------

    def _handle_static(self, method, path):
        rel = path.lstrip("/")
        if rel in ("", "/"):
            rel = "index.html"
        elif rel in ("admin", "login"):
            rel = rel + ".html"

        target = os.path.realpath(os.path.join(STATIC_DIR, rel))
        if not target.startswith(os.path.realpath(STATIC_DIR) + os.sep) and target != os.path.realpath(STATIC_DIR):
            self._send_error(403, "Forbidden")
            return
        if not os.path.isfile(target):
            self._send_error(404, "Not Found")
            return

        ctype = mimetypes.guess_type(target)[0] or "application/octet-stream"
        # PWA 清单用标准 MIME（Cloudflare Pages 会自动带，自建服务得手动指定）
        if rel == "manifest.json":
            ctype = "application/manifest+json"
        elif ctype.startswith("text/") or ctype in ("application/javascript", "application/json"):
            ctype += "; charset=utf-8"

        with open(target, "rb") as fh:
            data = fh.read()

        # og: 使用绝对地址（微信分享需要），按请求 Host 动态补全
        if rel == "index.html":
            base = "https://" if self.headers.get("X-Forwarded-Proto", "").lower() == "https" else "http://"
            origin = base + (self.headers.get("Host") or "")
            text = data.decode("utf-8")
            text = text.replace('content="/icons/icon-512.png"', 'content="%s/icons/icon-512.png"' % origin)
            text = text.replace('content="/"', 'content="%s/"' % origin)
            data = text.encode("utf-8")

        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", cache_control_for(path))
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "strict-origin-when-cross-origin")
        self.end_headers()
        if method != "HEAD":
            self.wfile.write(data)

    # ---------- 响应助手 ----------

    def _send_json(self, payload, status=200, extra_headers=None):
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        for name, value in (extra_headers or []):
            self.send_header(name, value)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def _send_error(self, status, message):
        data = message.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def log_message(self, fmt, *args):
        """只记异常请求。

        默认实现会把每个请求（每个图标、每次 /api）都写一行进 journald，
        个人站一天下来能攒出上百 MB，而真正有用的信息（4xx/5xx、报错）会被淹掉。

        注意：log_request 传进来的状态码是**字符串**（str(code)），不能直接
        isinstance(x, int) 判断，否则永远判不中、日志照旧刷屏。
        """
        code = args[1] if len(args) > 1 else None
        try:
            code = int(str(code))
        except (TypeError, ValueError):
            code = None
        if code is not None and 200 <= code < 400:
            return
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


# ============================================================
# 启动
# ============================================================

def main():
    init_db()
    # 启动时扫一遍缓存目录：万一上次是异常退出，别让临时文件留在盘上
    cleanup_bg_dir()
    conn = connect()
    try:
        ensure_seed(conn)
        migrate_settings(conn)
    finally:
        conn.close()

    server = ThreadingHTTPServer((HOST, PORT), Handler)
    server.daemon_threads = True
    print("Holiday VPS 已启动：http://%s:%d  静态目录=%s 数据库=%s" % (HOST, PORT, STATIC_DIR, DB_PATH), flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
