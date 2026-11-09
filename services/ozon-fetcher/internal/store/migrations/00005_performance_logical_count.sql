-- +goose Up
-- Application calls, not physical wire requests or quota enforcement.
CREATE TABLE performance_http_logical_count (
 bucket_start timestamptz NOT NULL,
 operation varchar(32) NOT NULL,
 attempts bigint NOT NULL CHECK (attempts > 0),
 PRIMARY KEY (bucket_start, operation)
);
-- +goose Down
-- Dropping this table invalidates any collected-window proof.
DROP TABLE performance_http_logical_count;
