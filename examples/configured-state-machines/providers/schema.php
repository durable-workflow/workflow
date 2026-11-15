<?php

declare(strict_types=1);

/** Install only in a separate, disposable SQLite provider database. No DW or application tables. */
return [
    'CREATE TABLE provider_plans (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, lose_ack INTEGER NOT NULL)',
    'CREATE TABLE provider_operations (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, request TEXT NOT NULL, status TEXT NOT NULL, closed INTEGER NOT NULL)',
    'CREATE TABLE provider_effects (sequence INTEGER PRIMARY KEY AUTOINCREMENT, operation_id TEXT NOT NULL UNIQUE, effect_id TEXT NOT NULL UNIQUE)',
    'CREATE TABLE provider_requests (sequence INTEGER PRIMARY KEY AUTOINCREMENT, operation_id TEXT NOT NULL, kind TEXT NOT NULL)',
];
