-- SmartBulk — drop all tables (used only when user explicitly opts into full data removal)

DROP TABLE IF EXISTS `{PREFIX}smartbulk_health_product`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_health_snapshot`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_schedule`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_job_queue`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_ai_cache`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_ai_run`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_prompt_version`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_prompt`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_massedit_log`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_massedit`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_action_template`;
DROP TABLE IF EXISTS `{PREFIX}smartbulk_segment`;
