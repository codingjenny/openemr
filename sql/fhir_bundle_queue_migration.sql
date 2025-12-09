-- Migration script to add FHIR Bundle Queue support
-- This allows FHIR Bundle API to process requests asynchronously for better reliability

#IfNotTable fhir_bundle_queue
CREATE TABLE `fhir_bundle_queue` (
  `id` bigint NOT NULL auto_increment,
  `bundle_json` LONGTEXT COMMENT 'The complete FHIR Bundle JSON',
  `status` varchar(20) DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
  `datetime_queued` datetime default NULL,
  `datetime_processed` datetime default NULL,
  `result_json` LONGTEXT COMMENT 'The result bundle response (transaction-response or batch-response)',
  `error` tinyint DEFAULT 0,
  `error_message` text,
  `datetime_error` datetime default NULL,
  `retry_count` int DEFAULT 0 COMMENT 'Number of retry attempts',
  `max_retries` int DEFAULT 3 COMMENT 'Maximum number of retries allowed',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `datetime_queued` (`datetime_queued`)
) ENGINE=InnoDb AUTO_INCREMENT=1;
#EndIf

#IfNotRow background_services name FHIR_Bundle_Service
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
('FHIR_Bundle_Service', 'FHIR Bundle Queue Processor', 1, 0, current_timestamp(), 1, 'fhirBundleServiceRun', '/library/fhir_bundle_service_run.php', 100);
#EndIf

