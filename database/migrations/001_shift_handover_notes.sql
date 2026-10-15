-- Upgrade an existing coffee_management database to add shift handover notes.
-- Existing accounts and business records are not changed.

USE coffee_management;

CREATE TABLE IF NOT EXISTS shift_handover_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note VARCHAR(500) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_handover_notes_open_created (resolved_at, created_at),
    CONSTRAINT fk_handover_notes_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_handover_notes_resolved_by
        FOREIGN KEY (resolved_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
