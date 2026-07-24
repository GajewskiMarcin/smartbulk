-- SmartBulk — database schema (MVP v1.0.0)
-- Prefix: {PREFIX}smartbulk_*  (e.g. ps_smartbulk_segment)
-- Multi-shop aware: all user-owned tables include id_shop.

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_segment` (
    `id_segment`     INT(11) NOT NULL AUTO_INCREMENT,
    `id_shop`        INT(11) NOT NULL,
    `id_shop_group`  INT(11) DEFAULT NULL,
    `name`           VARCHAR(255) NOT NULL,
    `description`    TEXT DEFAULT NULL,
    `combine_logic`  ENUM('and','or') NOT NULL DEFAULT 'and',
    `conditions`     JSON NOT NULL,
    `created_by`     INT(11) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL,
    `updated_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id_segment`),
    KEY `idx_shop` (`id_shop`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_action_template` (
    `id_template`  INT(11) NOT NULL AUTO_INCREMENT,
    `id_shop`      INT(11) NOT NULL,
    `name`         VARCHAR(255) NOT NULL,
    `description`  TEXT DEFAULT NULL,
    `actions`      JSON NOT NULL,
    `created_by`   INT(11) DEFAULT NULL,
    `created_at`   DATETIME NOT NULL,
    PRIMARY KEY (`id_template`),
    KEY `idx_shop` (`id_shop`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_massedit` (
    `id_massedit`       INT(11) NOT NULL AUTO_INCREMENT,
    `id_shop`           INT(11) NOT NULL,
    `id_segment`        INT(11) DEFAULT NULL,
    `id_template`       INT(11) DEFAULT NULL,
    `segment_snapshot`  JSON DEFAULT NULL,
    `action_snapshot`   JSON DEFAULT NULL,
    `mode`              ENUM('dry_run','apply') NOT NULL DEFAULT 'apply',
    `status`            ENUM('pending','running','success','failed','partial','undone') NOT NULL DEFAULT 'pending',
    `products_matched`  INT(11) NOT NULL DEFAULT 0,
    `products_changed`  INT(11) NOT NULL DEFAULT 0,
    `products_failed`   INT(11) NOT NULL DEFAULT 0,
    `started_at`        DATETIME NOT NULL,
    `finished_at`       DATETIME DEFAULT NULL,
    `id_employee`       INT(11) DEFAULT NULL,
    PRIMARY KEY (`id_massedit`),
    KEY `idx_shop_started` (`id_shop`, `started_at`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_massedit_log` (
    `id_log`        INT(11) NOT NULL AUTO_INCREMENT,
    `id_massedit`   INT(11) NOT NULL,
    `id_product`    INT(11) NOT NULL,
    `id_lang`       INT(11) DEFAULT NULL,
    `field`         VARCHAR(64) NOT NULL,
    `old_value`     MEDIUMTEXT DEFAULT NULL,
    `new_value`     MEDIUMTEXT DEFAULT NULL,
    `status`        ENUM('changed','unchanged','failed','skipped') NOT NULL DEFAULT 'changed',
    `error`         TEXT DEFAULT NULL,
    `changed_at`    DATETIME NOT NULL,
    PRIMARY KEY (`id_log`),
    KEY `idx_massedit` (`id_massedit`),
    KEY `idx_product` (`id_product`),
    KEY `idx_field` (`field`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_prompt` (
    `id_prompt`        INT(11) NOT NULL AUTO_INCREMENT,
    `slug`             VARCHAR(64) NOT NULL,
    `name`             VARCHAR(255) NOT NULL,
    `task_type`        ENUM('meta_title','meta_description','short_desc','long_desc','translate','alt_text','seo_rewrite','tagging','custom') NOT NULL,
    `id_shop`          INT(11) DEFAULT NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `current_version`  INT(11) DEFAULT NULL,
    `created_by`       INT(11) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL,
    PRIMARY KEY (`id_prompt`),
    UNIQUE KEY `idx_slug` (`slug`),
    KEY `idx_task_type` (`task_type`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_prompt_version` (
    `id_prompt_version`  INT(11) NOT NULL AUTO_INCREMENT,
    `id_prompt`          INT(11) NOT NULL,
    `version_number`     INT(11) NOT NULL,
    `parent_version`     INT(11) DEFAULT NULL,
    `system_prompt`      MEDIUMTEXT NOT NULL,
    `user_prompt`        MEDIUMTEXT NOT NULL,
    `model`              VARCHAR(64) NOT NULL,
    `provider`           ENUM('claude','openai') NOT NULL DEFAULT 'claude',
    `params`             JSON DEFAULT NULL,
    `notes`              TEXT DEFAULT NULL,
    `created_by`         INT(11) DEFAULT NULL,
    `created_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id_prompt_version`),
    KEY `idx_prompt_version` (`id_prompt`, `version_number`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_ai_run` (
    `id_run`             INT(11) NOT NULL AUTO_INCREMENT,
    `id_prompt_version`  INT(11) NOT NULL,
    `id_massedit`        INT(11) DEFAULT NULL,
    `id_product`         INT(11) NOT NULL,
    `id_lang`            INT(11) DEFAULT NULL,
    `id_shop`            INT(11) NOT NULL,
    `input_context`      JSON DEFAULT NULL,
    `output`             MEDIUMTEXT DEFAULT NULL,
    `tokens_in`          INT(11) DEFAULT NULL,
    `tokens_out`         INT(11) DEFAULT NULL,
    `cost_usd`           DECIMAL(10,6) DEFAULT NULL,
    `latency_ms`         INT(11) DEFAULT NULL,
    `status`             ENUM('pending','success','failed','rejected','accepted') NOT NULL DEFAULT 'pending',
    `error`              TEXT DEFAULT NULL,
    `created_at`         DATETIME NOT NULL,
    PRIMARY KEY (`id_run`),
    KEY `idx_prompt_version` (`id_prompt_version`),
    KEY `idx_product_created` (`id_product`, `created_at`),
    KEY `idx_status` (`status`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_ai_cache` (
    `cache_key`     VARCHAR(64) NOT NULL,
    `output`        MEDIUMTEXT NOT NULL,
    `created_at`    DATETIME NOT NULL,
    `hits`          INT(11) NOT NULL DEFAULT 0,
    `last_used_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`cache_key`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_job_queue` (
    `id_job`         INT(11) NOT NULL AUTO_INCREMENT,
    `type`           VARCHAR(64) NOT NULL,
    `payload`        JSON DEFAULT NULL,
    `priority`       TINYINT(4) NOT NULL DEFAULT 5,
    `status`         ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    `attempts`       INT(11) NOT NULL DEFAULT 0,
    `max_attempts`   INT(11) NOT NULL DEFAULT 3,
    `scheduled_at`   DATETIME NOT NULL,
    `started_at`     DATETIME DEFAULT NULL,
    `finished_at`    DATETIME DEFAULT NULL,
    `worker_id`      VARCHAR(64) DEFAULT NULL,
    `error`          TEXT DEFAULT NULL,
    `progress_pct`   TINYINT(4) NOT NULL DEFAULT 0,
    `id_shop`        INT(11) NOT NULL,
    `id_employee`    INT(11) DEFAULT NULL,
    PRIMARY KEY (`id_job`),
    KEY `idx_status_sched` (`status`, `scheduled_at`),
    KEY `idx_type` (`type`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_schedule` (
    `id_schedule`      INT(11) NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(255) NOT NULL,
    `id_shop`          INT(11) NOT NULL,
    `job_type`         VARCHAR(64) NOT NULL,
    `job_payload`      JSON DEFAULT NULL,
    `cron_expression`  VARCHAR(32) NOT NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `last_run_at`      DATETIME DEFAULT NULL,
    `next_run_at`      DATETIME DEFAULT NULL,
    `created_by`       INT(11) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL,
    PRIMARY KEY (`id_schedule`),
    KEY `idx_active_next` (`is_active`, `next_run_at`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_health_snapshot` (
    `id_snapshot`      INT(11) NOT NULL AUTO_INCREMENT,
    `id_shop`          INT(11) NOT NULL,
    `taken_at`         DATETIME NOT NULL,
    `products_total`   INT(11) NOT NULL DEFAULT 0,
    `metrics`          JSON DEFAULT NULL,
    PRIMARY KEY (`id_snapshot`),
    KEY `idx_shop_taken` (`id_shop`, `taken_at`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}smartbulk_health_product` (
    `id_product`      INT(11) NOT NULL,
    `id_shop`         INT(11) NOT NULL,
    `id_lang`         INT(11) NOT NULL,
    `seo_score`       TINYINT(4) NOT NULL DEFAULT 0,
    `content_score`   TINYINT(4) NOT NULL DEFAULT 0,
    `issues`          JSON DEFAULT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id_product`, `id_shop`, `id_lang`)
) ENGINE={ENGINE} DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
