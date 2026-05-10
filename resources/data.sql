-- end attached script 'category_data'
-- begin attached script 'settings_data'
SET
time_zone = '+00:00';

-- MySQL Workbench Forward Engineering

SET
@OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET
@OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET
@OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema sampel_db
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Schema sampel_db
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `sampel_db` DEFAULT CHARACTER SET utf8mb4;
USE
`sampel_db` ;

-- -----------------------------------------------------
-- Table `sampel_db`.`account`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`account`
(
    `id`
    INT
(
    11
) NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `type_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `industry_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `size_code` TINYINT
(
    2
) UNSIGNED NOT NULL,
    `slug` VARCHAR
(
    200
) NULL,
    `name` VARCHAR
(
    255
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `registration_code` VARCHAR
(
    100
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `display_name` VARCHAR
(
    20
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `contact_number` VARCHAR
(
    15
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `mobile_region_code` VARCHAR
(
    3
) NULL DEFAULT NULL,
    `mobile_number` VARCHAR
(
    15
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `fax_number` VARCHAR
(
    15
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `email` VARCHAR
(
    80
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `description` BLOB NULL DEFAULT NULL,
    `theme_color` VARCHAR
(
    45
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `web_url` TINYTEXT CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `country_code` VARCHAR
(
    4
) CHARACTER SET 'utf8' NULL DEFAULT 'MYR',
    `accepted_terms` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `type_code`
(
    `type_code`
    ASC
) VISIBLE,
    INDEX `registration_code`
(
    `registration_code`
    ASC
) VISIBLE,
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `email`
(
    `email`
    ASC
) VISIBLE,
    INDEX `country_code`
(
    `country_code`
    ASC
) VISIBLE,
    INDEX `mobile_number`
(
    `mobile_number`
    ASC
) VISIBLE,
    INDEX `industry_id`
(
    `industry_id`
    ASC
) VISIBLE,
    INDEX `size_code`
(
    `size_code`
    ASC
) VISIBLE,
    INDEX `slug`
(
    `slug`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`account_industry`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`account_industry`
(
    `id`
    INT
(
    11
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `industry_id` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `account_id` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `industry_id`
(
    `industry_id`
    ASC
) VISIBLE,
    INDEX `account_id`
(
    `account_id`
    ASC
) VISIBLE)
    ENGINE = MyISAM
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`activity_log`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`activity_log`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `scenario_code` TINYINT
(
    2
) UNSIGNED NULL DEFAULT 0,
    `model_type` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NULL DEFAULT 0,
    `data` BLOB NULL DEFAULT NULL,
    `user_agent` LONGTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `browser` TEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `version` VARCHAR
(
    45
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `country_code` CHAR
(
    3
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `host` TEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `ip_address` VARCHAR
(
    255
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `x_forwarded_for` VARCHAR
(
    255
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `scenario_code`
(
    `scenario_code`
    ASC
) VISIBLE,
    INDEX `country_code`
(
    `country_code`
    ASC
) VISIBLE,
    INDEX `model`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`address`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`address`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `model_type` VARCHAR
(
    100
) NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `usage_code` TINYINT
(
    2
) UNSIGNED NOT NULL DEFAULT '0',
    `is_default` TINYINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `building_name` TINYTEXT NULL DEFAULT NULL,
    `address` TINYTEXT NULL DEFAULT NULL,
    `street` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `district` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `city` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `province` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `type_number` VARCHAR
(
    45
) NULL DEFAULT NULL,
    `locality` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `state` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `postal_code` VARCHAR
(
    10
) NULL DEFAULT NULL,
    `country_code` VARCHAR
(
    10
) NULL DEFAULT NULL,
    `formatted_address` VARCHAR
(
    191
) NULL DEFAULT NULL,
    `latitude` FLOAT NULL DEFAULT NULL,
    `longitude` FLOAT NULL DEFAULT NULL,
    `keywords` TEXT NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `model_type`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE,
    INDEX `usage_code`
(
    `usage_code`
    ASC
) VISIBLE,
    INDEX `is_default`
(
    `is_default`
    ASC
) VISIBLE,
    INDEX `country_code`
(
    `country_code`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`administrator`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`administrator`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `role_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `role_code`
(
    `role_code`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`category`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`category`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0' COMMENT '1 = active, 999 - deleted',
    `parent_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `name` VARCHAR
(
    200
) CHARACTER SET 'latin1' NULL DEFAULT NULL,
    `slug` VARCHAR
(
    200
) NULL DEFAULT NULL,
    `description` BLOB NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `status_code`
    ASC,
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `slug`
(
    `slug`
    ASC
) VISIBLE,
    INDEX `parent_id`
(
    `parent_id`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`category_account`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`category_account`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `category_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `account_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `category_id`
(
    `category_id`
    ASC
) VISIBLE,
    INDEX `account_id`
(
    `account_id`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`contact`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`contact`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `model_type` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NULL DEFAULT 0,
    `usage_code` TINYINT
(
    2
) UNSIGNED NULL DEFAULT 0,
    `is_default` TINYINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `content` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
))
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`file`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`file`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `total_viewed` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `model_type` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `usage_code` TINYINT
(
    2
) UNSIGNED NOT NULL DEFAULT '0',
    `is_default` TINYINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `name` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `actual_name` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `mime_type` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `extension` VARCHAR
(
    8
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `size` DOUBLE UNSIGNED NOT NULL DEFAULT '0',
    `description` TINYTEXT NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `total_viewed`
(
    `total_viewed`
    ASC
) VISIBLE,
    INDEX `model_type`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE,
    INDEX `usage_code`
(
    `usage_code`
    ASC
) VISIBLE,
    INDEX `is_default`
(
    `is_default`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`gallery`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`gallery`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `model_type` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `usage_code` TINYINT
(
    2
) UNSIGNED NOT NULL DEFAULT '0',
    `is_default` TINYINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `total_viewed` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `url_referrer` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `name` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `caption` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `alt` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `title` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `actual_name` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `mime_type` VARCHAR
(
    45
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `extension` VARCHAR
(
    45
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `size` DOUBLE UNSIGNED NOT NULL DEFAULT '0',
    `description` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `width` SMALLINT
(
    5
) UNSIGNED NOT NULL DEFAULT '0',
    `height` SMALLINT
(
    5
) UNSIGNED NOT NULL DEFAULT '0',
    `meta_data` TEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `dominant_color` VARCHAR
(
    45
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `color_palette` BLOB NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `model_type`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE,
    INDEX `usage_code`
(
    `usage_code`
    ASC
) VISIBLE,
    INDEX `is_default`
(
    `is_default`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`industry`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`industry`
(
    `id`
    INT
(
    11
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `name` VARCHAR
(
    255
) CHARACTER SET 'utf8' NULL DEFAULT NULL,
    `slug` VARCHAR
(
    200
) NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `slug`
(
    `slug`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `sampel_db`.`password_log`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`password_log`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT 0,
    `password` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
))
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`user`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`user`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `src` VARCHAR
(
    100
) NULL DEFAULT NULL,
    `status_code` SMALLINT
(
    1
) UNSIGNED NOT NULL DEFAULT '0',
    `model_type` VARCHAR
(
    100
) NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `username` VARCHAR
(
    45
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL COMMENT 'aka display name',
    `password` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `verification_code` TINYTEXT CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `activated` TIMESTAMP NULL DEFAULT NULL,
    `recovery_sent` TIMESTAMP NULL DEFAULT NULL,
    `salt` CHAR
(
    10
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `last_login` TIMESTAMP NULL DEFAULT NULL,
    `last_password_updated` TIMESTAMP NULL DEFAULT NULL,
    `declare_authorized` TIMESTAMP NULL DEFAULT NULL,
    `preferred_language` VARCHAR
(
    6
) NULL DEFAULT 'en',
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `status_code`
(
    `status_code`
    ASC
) VISIBLE,
    INDEX `username`
(
    `username`
    ASC
) VISIBLE,
    INDEX `model_type`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`user_profile`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`user_profile`
(
    `id`
    INT
(
    10
) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `src` VARCHAR
(
    100
) NULL DEFAULT NULL,
    `model_type` VARCHAR
(
    100
) NULL DEFAULT NULL,
    `model_id` INT
(
    10
) UNSIGNED NOT NULL DEFAULT '0',
    `first_name` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `middle_name` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `last_name` VARCHAR
(
    191
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `dob` DATE NULL DEFAULT NULL,
    `identity_number_type` TINYINT
(
    3
) UNSIGNED NOT NULL DEFAULT '0',
    `identity_number` VARCHAR
(
    100
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `gender_code` CHAR
(
    1
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `marital_status_code` TINYINT
(
    3
) UNSIGNED NOT NULL DEFAULT '0',
    `ethnic_code` TINYINT
(
    3
) UNSIGNED NOT NULL DEFAULT '0',
    `religion_code` TINYINT
(
    3
) UNSIGNED NOT NULL DEFAULT '0',
    `nationality` CHAR
(
    3
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `email` VARCHAR
(
    50
) CHARACTER SET 'utf8mb4' NULL DEFAULT NULL,
    `contact_number` VARCHAR
(
    45
) NULL DEFAULT NULL,
    `mobile_region_code` VARCHAR
(
    3
) NULL DEFAULT NULL,
    `mobile_number` VARCHAR
(
    45
) NULL DEFAULT NULL,
    PRIMARY KEY
(
    `id`
),
    INDEX `created_by`
(
    `created_by`
    ASC
) VISIBLE,
    INDEX `updated_by`
(
    `updated_by`
    ASC
) VISIBLE,
    INDEX `identity_number`
(
    `identity_number`
    ASC
) VISIBLE,
    INDEX `gender_code`
(
    `gender_code`
    ASC
) VISIBLE,
    INDEX `marital_status_code`
(
    `marital_status_code`
    ASC
) VISIBLE,
    INDEX `ethnic_code`
(
    `ethnic_code`
    ASC
) VISIBLE,
    INDEX `religion_code`
(
    `religion_code`
    ASC
) VISIBLE,
    INDEX `nationality`
(
    `nationality`
    ASC
) VISIBLE,
    INDEX `email`
(
    `email`
    ASC
) VISIBLE,
    INDEX `model_type`
(
    `model_type`
    ASC
) VISIBLE,
    INDEX `model_id`
(
    `model_id`
    ASC
) VISIBLE,
    INDEX `mobile_region_code`
(
    `mobile_region_code`
    ASC
) VISIBLE,
    INDEX `mobile_number`
(
    `mobile_number`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4
    COLLATE = utf8mb4_unicode_520_ci;


-- -----------------------------------------------------
-- Table `sampel_db`.`settings`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sampel_db`.`settings`
(
    `id`
    INT
(
    11
) NOT NULL AUTO_INCREMENT,
    `created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT
(
    11
) UNSIGNED NOT NULL DEFAULT '0',
    `section` VARCHAR
(
    100
) NULL,
    `slug` VARCHAR
(
    100
) NULL,
    `title` VARCHAR
(
    255
) NULL,
    `description` TINYTEXT NULL,
    `default` VARCHAR
(
    100
) NULL,
    `value` TINYTEXT NULL,
    `is_required` TINYINT
(
    1
) NULL DEFAULT 0,
    PRIMARY KEY
(
    `id`
),
    INDEX `slug`
(
    `slug`
    ASC
) VISIBLE)
    ENGINE = InnoDB
    DEFAULT CHARACTER SET = utf8mb4;


SET
SQL_MODE=@OLD_SQL_MODE;
SET
FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET
UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
-- begin attached script 'industry_data'
--
-- Dumping data for table `industry`
--

INSERT INTO `industry` (`id`, `created`, `created_by`, `updated`, `updated_by`, `status_code`, `name`, `slug`)
VALUES (1, NULL, 2, NULL, 3, 999, 'Customer Service/ Helpdesk', NULL),
       (2, NULL, 2, '2021-03-03 17:16:04', 1, 1, 'Banking', NULL),
       (3, NULL, 2, NULL, 2, 999, 'Hospitality', NULL),
       (4, NULL, 2, NULL, 3, 999, 'Admin/ Clerical', NULL),
       (5, NULL, 2, '2021-03-03 17:16:04', 1, 1, 'Art', NULL),
       (6, NULL, 2, NULL, 3, 999, 'Building/Construction', NULL),
       (7, NULL, 2, '2021-03-03 17:16:04', 1, 1, 'Computing', NULL),
       (8, NULL, 2, '2021-03-03 17:16:04', 1, 1, 'Hotel', NULL),
       (9, NULL, 2, NULL, 3, 999, 'Sales & Marketing', NULL),
       (10, NULL, 2, '2021-03-03 17:16:04', 1, 1, 'Transportation', NULL),
       (11, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Wood', NULL),
       (12, NULL, 3, NULL, 3, 999, 'Business/ Management Consulting', NULL),
       (13, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Call Center', NULL),
       (14, NULL, 6, '2021-03-03 17:16:04', 1, 1, 'Food & Beverage (F&B)', NULL),
       (15, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Tobacco', NULL),
       (16, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Telecommunication', NULL),
       (17, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Marine', NULL),
       (18, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Social Services', NULL),
       (19, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Automobile', NULL),
       (20, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Legal', NULL),
       (21, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Mining', NULL),
       (22, NULL, 3, NULL, 3, 999, 'Engineering/ Technical Consulting', NULL),
       (23, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Manufacturing', NULL),
       (24, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Staffing', NULL),
       (25, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Heavy Industrial', NULL),
       (26, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Education', NULL),
       (27, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Retail', NULL),
       (28, NULL, 3, NULL, 3, 999, 'Information Technology (IT)', NULL),
       (29, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Entertainment', NULL),
       (30, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Aviation', NULL),
       (31, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Beauty', NULL),
       (32, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Consumer Product', NULL),
       (33, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Constructions', NULL),
       (34, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'BioTechnology', NULL),
       (35, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Oil', NULL),
       (36, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Accounting', NULL),
       (37, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Agricultural', NULL),
       (38, NULL, 3, NULL, 3, 999, 'Architecture/ Interior Design', NULL),
       (39, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Exhibitions', NULL),
       (40, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Healthcare', NULL),
       (41, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Journalism', NULL),
       (42, NULL, 3, '2021-03-03 17:16:04', 1, 1, 'Environment', NULL),
       (43, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Stockbroking', NULL),
       (44, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Insurance', NULL),
       (45, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Property', NULL),
       (46, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Purchase', NULL),
       (47, NULL, 3, NULL, 3, 999, 'Other Industries', NULL),
       (48, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Library', NULL),
       (49, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Security', NULL),
       (50, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Government', NULL),
       (51, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Sports', NULL),
       (52, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Casino', NULL),
       (53, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Clergy', NULL),
       (54, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Utilities', NULL),
       (55, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Electrical & Electronics', NULL),
       (56, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Multimedia', NULL),
       (57, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Computing', NULL),
       (58, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Advertising', NULL),
       (59, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Textiles', NULL),
       (60, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Consulting (Business & Management)', NULL),
       (61, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Consulting (IT, Science, Engineering, Technical)', NULL),
       (62, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Insurance', NULL),
       (63, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Architectural Services', NULL),
       (64, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Genera; & Wholesale Trading', NULL),
       (65, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Chemical', NULL),
       (66, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Polymer', NULL),
       (67, NULL, 3, '2021-03-03 17:16:05', 1, 1, 'Printing', NULL),
       (68, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Financial Services', NULL),
       (69, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Design', NULL),
       (70, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Fashion', NULL),
       (71, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Information Technology (Hardware)', NULL),
       (72, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Hospitality', NULL),
       (73, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Tourism Services', NULL),
       (74, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Logistics', NULL),
       (75, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Fiber', NULL),
       (76, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Paper', NULL),
       (77, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Business Process Outsourcing (BPO)', NULL),
       (78, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'IT-Enabled Services', NULL),
       (79, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Catering', NULL),
       (80, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Restaurant', NULL),
       (81, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Aquaculture', NULL),
       (82, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'NGO', NULL),
       (83, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Automotive Ancillary', NULL),
       (84, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Vehicle', NULL),
       (85, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Law', NULL),
       (86, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Production', NULL),
       (87, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Recruiting', NULL),
       (88, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Machinery', NULL),
       (89, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Training', NULL),
       (90, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Merchandising', NULL),
       (91, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Media', NULL),
       (92, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Airlines', NULL),
       (93, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Aerospace', NULL),
       (94, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Fitness', NULL),
       (95, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'FMCG', NULL),
       (96, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Building', NULL),
       (97, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Engineering', NULL),
       (98, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Pharmaceutical', NULL),
       (99, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Clinic Research', NULL),
       (100, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Gas', NULL),
       (101, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Petroleum', NULL),
       (102, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Audit', NULL),
       (103, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Tax Services', NULL),
       (104, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Plantation', NULL),
       (105, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Poultry', NULL),
       (106, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Fisheries', NULL),
       (107, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Event Management', NULL),
       (108, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'MICE', NULL),
       (109, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Medical', NULL),
       (110, '2021-03-03 17:16:04', 1, '2021-03-03 17:16:04', 1, 1, 'Health', NULL),
       (111, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Safety', NULL),
       (112, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Securities', NULL),
       (113, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Real Estate', NULL),
       (114, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Supply Chain', NULL),
       (115, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Museum', NULL),
       (116, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Law Enforcement', NULL),
       (117, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Defence', NULL),
       (118, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Gambling', NULL),
       (119, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Religious Organizations', NULL),
       (120, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Power', NULL),
       (121, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Graphic Design', NULL),
       (122, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Information Technology (Software)', NULL),
       (123, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Marketing', NULL),
       (124, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Promotion', NULL),
       (125, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'PR', NULL),
       (126, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Garment', NULL),
       (127, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Interiorior Designing', NULL),
       (128, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Fertilizers', NULL),
       (129, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Pesticides', NULL),
       (130, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Plastic', NULL),
       (131, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Rubber', NULL),
       (132, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Tyres', NULL),
       (133, '2021-03-03 17:16:05', 1, '2021-03-03 17:16:05', 1, 1, 'Publishing', NULL);

-- end attached script 'industry_data'
-- begin attached script 'category_data'
--
-- Dumping data for table `category`
--

INSERT INTO `category` (`id`, `created`, `created_by`, `updated`, `updated_by`, `status_code`, `parent_id`, `name`,
                        `slug`, `description`)
VALUES (1, '2020-04-18 02:17:58', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Accounting', NULL, NULL),
       (2, '2020-04-18 02:21:31', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Admin', NULL, NULL),
       (3, '2020-04-18 02:23:13', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Agriculture', NULL, NULL),
       (4, '2020-04-18 02:23:59', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Arts', NULL, NULL),
       (5, '2020-04-18 02:23:59', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Building', NULL, NULL),
       (6, '2020-04-18 02:24:00', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Customer Services', NULL, NULL),
       (7, '2020-04-18 02:24:00', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Education', NULL, NULL),
       (8, '2020-04-18 02:24:42', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Engineering', NULL, NULL),
       (9, '2020-04-18 02:26:02', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Food', NULL, NULL),
       (10, '2020-04-18 02:26:02', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Health', NULL, NULL),
       (11, '2020-04-18 02:26:02', 0, '2021-08-04 19:54:11', 0, 1, 0, 'IT', NULL, NULL),
       (12, '2020-04-18 02:26:02', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Journalism', NULL, NULL),
       (13, '2020-04-18 02:26:02', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Manufacturing', NULL, NULL),
       (14, '2020-04-18 02:27:54', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Research Development (R&D)', NULL, NULL),
       (15, '2020-04-18 02:27:54', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Sales', NULL, NULL),
       (16, '2020-04-18 02:27:54', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Social Service', NULL, NULL),
       (17, '2020-04-18 02:27:54', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Transportation', NULL, NULL),
       (18, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Finance', NULL, NULL),
       (19, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'HR Resource', NULL, NULL),
       (20, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Aquaculture', NULL, NULL),
       (21, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Design', NULL, NULL),
       (22, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Construction', NULL, NULL),
       (23, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Training', NULL, NULL),
       (24, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Maintenance', NULL, NULL),
       (25, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Beverage', NULL, NULL),
       (26, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Beauty', NULL, NULL),
       (27, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Sports', NULL, NULL),
       (28, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Science & Technology', NULL, NULL),
       (29, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Media', NULL, NULL),
       (30, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Marketing', NULL, NULL),
       (31, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Environment', NULL, NULL),
       (32, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Mining', NULL, NULL),
       (33, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Biz Development', NULL, NULL),
       (34, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Security', NULL, NULL),
       (35, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Defence', NULL, NULL),
       (36, '2021-02-04 17:47:45', 0, '2021-08-04 19:54:11', 0, 1, 0, 'Logistics', NULL, NULL);


-- end attached script 'app_user'
