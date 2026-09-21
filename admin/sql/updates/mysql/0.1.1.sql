-- Additive durable-job/artifact upgrade. Existing definitions, plans and executions are preserved.
CREATE TABLE IF NOT EXISTS `#__joomengine_mcp_job` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(36) NOT NULL DEFAULT '',
  `principal_key` VARCHAR(64) NOT NULL DEFAULT '',
  `principal_id` VARCHAR(190) NOT NULL DEFAULT '',
  `track` VARCHAR(190) NOT NULL DEFAULT '',
  `action_name` VARCHAR(190) NOT NULL DEFAULT '',
  `execution_uuid` VARCHAR(36) NOT NULL DEFAULT '',
  `token_hash` VARCHAR(64) NOT NULL DEFAULT '',
  `payload_cipher` MEDIUMTEXT NOT NULL,
  `result_cipher` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(190) NOT NULL DEFAULT '',
  `progress` INT NOT NULL DEFAULT 0,
  `message` MEDIUMTEXT NOT NULL,
  `cancel_requested` INT NOT NULL DEFAULT 0,
  `worker_uuid` VARCHAR(36) NOT NULL DEFAULT '',
  `lease_until` BIGINT NOT NULL DEFAULT 0,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `updated_at` BIGINT NOT NULL DEFAULT 0,
  `expires_at` BIGINT NOT NULL DEFAULT 0,
  `version` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE (`uuid`),
  UNIQUE (`execution_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE INDEX `#__jemcp_job_principal_key` ON `#__joomengine_mcp_job` (`principal_key`);
CREATE INDEX `#__jemcp_job_expires_at` ON `#__joomengine_mcp_job` (`expires_at`);

CREATE TABLE IF NOT EXISTS `#__joomengine_mcp_artifact` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(36) NOT NULL DEFAULT '',
  `principal_key` VARCHAR(64) NOT NULL DEFAULT '',
  `job_uuid` VARCHAR(36) NOT NULL DEFAULT '',
  `name` VARCHAR(190) NOT NULL DEFAULT '',
  `mime_type` VARCHAR(190) NOT NULL DEFAULT '',
  `size` BIGINT NOT NULL DEFAULT 0,
  `sha256` VARCHAR(64) NOT NULL DEFAULT '',
  `chunk_hashes` MEDIUMTEXT NOT NULL,
  `created_at` BIGINT NOT NULL DEFAULT 0,
  `expires_at` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE INDEX `#__jemcp_artifact_principal_key` ON `#__joomengine_mcp_artifact` (`principal_key`);
CREATE INDEX `#__jemcp_artifact_expires_at` ON `#__joomengine_mcp_artifact` (`expires_at`);

