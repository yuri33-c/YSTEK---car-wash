CREATE TABLE IF NOT EXISTS ystek_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS ystek_sessions_updated_at_idx
    ON ystek_sessions (updated_at);
