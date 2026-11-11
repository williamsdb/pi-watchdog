CREATE TABLE IF NOT EXISTS checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('ping', 'http')),
    target TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_id INTEGER NOT NULL,
    checked_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now')),
    status TEXT NOT NULL CHECK (status IN ('up', 'down')),
    response_time REAL,
    http_code INTEGER,
    error TEXT,
    FOREIGN KEY (check_id) REFERENCES checks(id)
);

CREATE INDEX IF NOT EXISTS idx_results_check_time
    ON results (check_id, checked_at);
