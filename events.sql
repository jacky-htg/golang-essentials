-- events.users definition

CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(60) NOT NULL,
  `username` varchar(50) NOT NULL,
  `name` varchar(50) NOT NULL,
  `password` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `is_actived` tinyint(1) NOT NULL DEFAULT '0',
  `is_verified_email` tinyint(1) NOT NULL DEFAULT '0',
  `role` char(1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- events.certificate_templates definition

CREATE TABLE `certificate_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `source` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificate_templates_UN` (`name`),
  KEY `certificate_templates_FK` (`created_by`),
  KEY `certificate_templates_FK_1` (`updated_by`),
  CONSTRAINT `certificate_templates_FK` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `certificate_templates_FK_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- events.events definition

CREATE TABLE `events` (
  `id` binary(16) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `date` datetime NOT NULL,
  `speaker` varchar(100) DEFAULT NULL,
  `number_of_participant` smallint unsigned NOT NULL,
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `certificate_template_id` int unsigned NOT NULL,
  `has_send_certificate` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int unsigned NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `events_FK` (`created_by`),
  KEY `events_FK_1` (`updated_by`),
  KEY `events_FK_2` (`certificate_template_id`),
  CONSTRAINT `events_FK` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `events_FK_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `events_FK_2` FOREIGN KEY (`certificate_template_id`) REFERENCES `certificate_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE DEFINER=`root`@`%` TRIGGER `before_insert_events` BEFORE INSERT ON `events` FOR EACH ROW SET new.id = UUID_TO_BIN(uuid());

-- events.participants definition

CREATE TABLE `participants` (
  `id` binary(16) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `nim` char(12) NOT NULL,
  `no_wa` char(13) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participants_UN_email` (`email`),
  UNIQUE KEY `participants_UN_nim` (`nim`),
  UNIQUE KEY `participants_UN_wa` (`no_wa`),
  UNIQUE KEY `participants_UN_user_id` (`user_id`),
  CONSTRAINT `participants_FK` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE DEFINER=`root`@`%` TRIGGER `before_insert_participants` BEFORE INSERT ON `participants` FOR EACH ROW SET new.id = UUID_TO_BIN(uuid());

-- events.events_participants definition

CREATE TABLE `events_participants` (
  `id` binary(16) NOT NULL,
  `participant_id` binary(16) NOT NULL,
  `event_id` binary(16) NOT NULL,
  `is_present` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `events_participants_UN` (`participant_id`,`event_id`),
  KEY `events_participants_FK_1` (`event_id`),
  CONSTRAINT `events_participants_FK` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `events_participants_FK_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE DEFINER=`root`@`%` TRIGGER `before_insert_events_participants` BEFORE INSERT ON `events_participants` FOR EACH ROW SET new.id = UUID_TO_BIN(uuid());
