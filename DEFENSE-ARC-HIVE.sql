-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 04, 2025 at 12:00 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('college','office') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_type` (`name`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `type`) VALUES
(1, 'College of Agriculture and Forestry', 'college'),
(2, 'College of Arts and Sciences', 'college'),
(3, 'College of Business and Management', 'college'),
(4, 'College of Education', 'college'),
(5, 'College of Engineering and Technology', 'college'),
(6, 'College of Veterinary Medicine', 'college'),
(7, 'Admission and Registration Services', 'office'),
(8, 'Audit Offices', 'office'),
(9, 'External Linkages and International Affairs', 'office'),
(10, 'Management Information Systems', 'office'),
(11, 'Office of the President', 'office');

-- --------------------------------------------------------

--
-- Table structure for table `sub_departments`
--

CREATE TABLE `sub_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_subdept` (`department_id`, `name`),
  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_departments`
--

INSERT INTO `sub_departments` (`id`, `department_id`, `name`) VALUES
(1, 4, 'Bachelor of Elementary Education'),
(2, 4, 'Early Childhood Education'),
(3, 4, 'Secondary Education'),
(4, 4, 'Technology and Livelihood Education'),
(5, 2, 'BS Development Communication'),
(6, 2, 'BS Psychology'),
(7, 2, 'AB Economics'),
(8, 5, 'BS Geodetic Engineering'),
(9, 5, 'BS Agricultural and Biosystems Engineering'),
(10, 5, 'BS Information Technology'),
(11, 3, 'BS Business Administration'),
(12, 3, 'BS Tourism Management'),
(13, 3, 'BS Entrepreneurship'),
(14, 3, 'BS Agribusiness'),
(15, 1, 'BS Agriculture'),
(16, 1, 'BS Forestry'),
(17, 1, 'BS Animal Science'),
(18, 1, 'BS Food Technology'),
(19, 6, 'Doctor of Veterinary Medicine');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `role` enum('admin','client') NOT NULL DEFAULT 'client',
  `profile_pic` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `profile_pic`, `position`, `created_at`) VALUES
(6, 'Karl_Faye', '$2y$10$Stt7c0HWQ6OQLGYbp/bdquVsVyAIH/EgewCv14pl3ry/gR7IphQFK', 'Tester', 'client', NULL, 'Tester', '2025-03-16 09:36:28'),
(7, 'boss', '$2y$10$9RBgwn5u02n71wC55OMhU.RmvTPfN7tj2WUB2rY6WChBd0WkPlxMG', 'Boss', 'admin', 'uploads/67d7a71f05bf6.png', 'Boss', '2025-03-17 04:37:51'),
(8, 'administrator', '$2y$10$pwKgtW57jALhx/PqIj3CxeZcpJjFKC8l/Ribbzyck2Ir7IXQ9Er0e', 'Tento Belebento', 'admin', NULL, 'Administrator', '2025-03-17 12:56:19'),
(9, 'President', '$2y$10$ANVkUN7rlTmMNVIgqSsLhu7RzcO37Z734URapUIyiKPMMNNs4StHq', 'President', 'admin', NULL, 'President', '2025-03-17 12:56:59'),
(10, 'Trevor_Mundo', '$2y$10$uv2Q/VDISAkVggfX92u1GeB9SVZRWryEAN0Mq8Cba1ugPtPMNFU8W', 'Trevor Mundo', 'client', NULL, 'Client', '2025-03-19 18:52:12');

-- --------------------------------------------------------

--
-- Table structure for table `user_department_affiliations`
--
CREATE TABLE `user_department_affiliations` (
  `user_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `sub_department_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`, `department_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sub_department_id`) REFERENCES `sub_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_department_affiliations`
--

INSERT INTO `user_department_affiliations` (`user_id`, `department_id`, `sub_department_id`) VALUES
(6, 11, NULL),
(6, 8, NULL),
(7, 11, NULL),
(7, 10, NULL),
(8, 11, NULL),
(8, 10, NULL),
(9, 11, NULL),
(10, 5, 10);

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `name`) VALUES
(4, 'announcement'),
(5, 'invitation'),
(2, 'letter'),
(1, 'memo'),
(3, 'notice');

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

CREATE TABLE `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int(11) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_type` enum('pdf','doc','docx','xls','xlsx','jpg','png','txt','zip','other') NOT NULL,
  `hard_copy_available` tinyint(1) DEFAULT 0,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `document_type_id` int(11) NOT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  INDEX `file_path_idx` (`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `files`
--

INSERT INTO `files` (`id`, `file_name`, `file_path`, `upload_date`, `user_id`, `file_size`, `file_type`, `hard_copy_available`, `is_deleted`, `deleted_at`, `document_type_id`, `status`) VALUES
(10, 'TODAY', 'physical/10', '2025-03-23 20:23:29', 8, NULL, 'pdf', 1, 0, NULL, 5, 'pending'),
(11, 'ANNOUNEMENT TODAY', 'physical/11', '2025-03-23 21:27:37', 7, NULL, 'pdf', 1, 0, NULL, 4, 'pending'),
(12, 'Presentation Script for Slides 48-49 (1).pdf', 'uploads/fad42476202b70d6_PresentationScriptforSlides48-491.pdf', '2025-03-24 04:30:05', 10, 138173, 'pdf', 0, 0, NULL, 3, 'pending'),
(13, 'LETTER OF MINE', 'physical/13', '2025-03-23 22:11:58', 7, NULL, 'pdf', 1, 0, NULL, 2, 'pending'),
(14, 'LETTER OF OURS', 'physical/14', '2025-03-23 22:19:23', 8, NULL, 'pdf', 1, 0, NULL, 2, 'pending'),
(15, 'NOTICE ME PLEASE', 'physical/15', '2025-03-24 05:28:13', 8, NULL, 'pdf', 1, 0, NULL, 3, 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `file_metadata`
--

CREATE TABLE `file_metadata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `meta_key` varchar(255) NOT NULL,
  `meta_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_key` (`file_id`, `meta_key`),
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `file_metadata`
--

INSERT INTO `file_metadata` (`id`, `file_id`, `meta_key`, `meta_value`) VALUES
(1, 10, 'course_information', 'TODAY'),
(2, 10, 'participants', 'TODAY'),
(3, 10, 'schedule_location', 'TODAY'),
(4, 10, 'teachers', 'TODAY'),
(5, 10, 'registration_link', 'TODAY'),
(6, 11, 'announcement_details', '12'),
(7, 11, 'effective_date', '1111-11-11'),
(8, 11, 'contact_person', 'ME'),
(9, 13, 'recipient_letter', 'LETTER'),
(10, 13, 'address_letter', 'LETTER'),
(11, 13, 'subject_letter', 'LETTER'),
(12, 14, 'recipient_letter', 'OURS'),
(13, 14, 'address_letter', 'OURS'),
(14, 14, 'subject_letter', 'OURS'),
(15, 15, 'notice_date', '1111-11-11'),
(16, 15, 'details_notice', 'NOTCED'),
(17, 15, 'contact_info_notice', 'NOTICER');

-- --------------------------------------------------------

--
-- Table structure for table `cabinets`
--

CREATE TABLE `cabinets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cabinet_name` varchar(255) NOT NULL,
  `department_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `layers` int(11) NOT NULL,
  `boxes` int(11) NOT NULL,
  `folders` int(11) NOT NULL,
  `folder_capacity` int(11) NOT NULL DEFAULT 5,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cabinets`
--

INSERT INTO `cabinets` (`id`, `cabinet_name`, `department_id`, `location`, `layers`, `boxes`, `folders`, `folder_capacity`) VALUES
(1, 'MIS', 10, 'Main Building', 2, 4, 6, 5),
(2, 'President Cabinet 2', 11, 'Main Office', 1, 1, 10, 5),
(3, 'President Cabinet 3', 11, 'Main Office', 1, 1, 10, 5),
(4, 'President Cabinet', 11, 'Main Building', 1, 1, 1, 5),
(5, 'President Cabinet 4', 11, 'Main Building', 1, 1, 40, 5),
(6, 'ITC', 5, 'ITC-Faculty', 1, 1, 10, 5);

-- --------------------------------------------------------

--
-- Table structure for table `storage_locations`
--

CREATE TABLE `storage_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cabinet_id` int(11) NOT NULL,
  `layer` int(11) NOT NULL,
  `box` int(11) NOT NULL,
  `folder` int(11) NOT NULL,
  `is_occupied` tinyint(1) DEFAULT 0,
  `file_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_location` (`cabinet_id`, `layer`, `box`, `folder`),
  FOREIGN KEY (`cabinet_id`) REFERENCES `cabinets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `storage_locations` (partial sample)
--

INSERT INTO `storage_locations` (`id`, `cabinet_id`, `layer`, `box`, `folder`, `is_occupied`, `file_count`) VALUES
(1, 1, 1, 1, 1, 1, 2),
(61, 5, 1, 1, 1, 1, 3),
(87, 5, 1, 1, 27, 1, 1),
(88, 5, 1, 1, 28, 1, 1),
(92, 5, 1, 1, 32, 1, 1),
(95, 5, 1, 1, 35, 1, 1),
(98, 5, 1, 1, 38, 1, 1),
(99, 5, 1, 1, 39, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `file_storage`
--

CREATE TABLE `file_storage` (
  `file_id` int(11) NOT NULL,
  `storage_location_id` int(11) NOT NULL,
  PRIMARY KEY (`file_id`),
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`storage_location_id`) REFERENCES `storage_locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `file_storage`
--

INSERT INTO `file_storage` (`file_id`, `storage_location_id`) VALUES
(10, 88),
(11, 95),
(13, 98),
(14, 92),
(15, 99);

-- --------------------------------------------------------

--
-- Table structure for table `file_transfers`
--

CREATE TABLE `file_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `time_sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `time_received` timestamp NULL DEFAULT NULL,
  `time_accepted` timestamp NULL DEFAULT NULL,
  `time_denied` timestamp NULL DEFAULT NULL,
  `status` enum('pending','accepted','denied') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `file_transfers`
--

INSERT INTO `file_transfers` (`id`, `file_id`, `sender_id`, `recipient_id`, `department_id`, `time_sent`, `time_received`, `time_accepted`, `time_denied`, `status`) VALUES
(1, 10, 8, 7, NULL, '2025-03-24 03:55:55', '2025-03-24 04:13:28', '2025-03-24 04:13:28', NULL, 'accepted'),
(2, 11, 7, 10, NULL, '2025-03-24 04:27:49', '2025-03-24 04:28:17', '2025-03-24 04:28:17', NULL, 'accepted'),
(3, 12, 10, 7, NULL, '2025-03-24 04:30:16', '2025-03-24 04:30:53', '2025-03-24 04:30:53', NULL, 'accepted');

-- --------------------------------------------------------

--
-- Table structure for table `access_requests`
--

CREATE TABLE `access_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `time_requested` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `time_approved` timestamp NULL DEFAULT NULL,
  `time_rejected` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `access_requests`
--

INSERT INTO `access_requests` (`id`, `requester_id`, `file_id`, `owner_id`, `status`, `time_requested`, `time_approved`, `time_rejected`) VALUES
(11, 8, 12, 10, 'approved', '2025-03-24 04:31:11', '2025-03-24 04:31:33', NULL),
(12, 6, 12, 10, 'approved', '2025-03-24 05:05:49', '2025-03-24 05:06:37', NULL),
(13, 10, 10, 8, 'approved', '2025-03-24 05:08:54', '2025-03-24 05:09:30', NULL),
(14, 10, 13, 7, 'pending', '2025-03-24 05:12:11', NULL, NULL),
(15, 10, 15, 8, 'approved', '2025-03-24 05:41:33', '2025-03-24 05:41:57', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `file_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `action`, `timestamp`, `file_id`, `department_id`) VALUES
(1, 8, 'Sent invitation: TODAY to boss', '2025-03-24 03:55:55', 10, NULL),
(2, 7, 'Accepted invitation: TODAY from administrator', '2025-03-24 04:13:28', 10, NULL),
(3, 10, 'Uploaded new (notice): Presentation Script for Slides 48-49 (1).pdf', '2025-03-24 04:30:05', 12, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `file_id` int(11) DEFAULT NULL,
  `status` enum('pending','accepted','denied','approved','rejected','completed') DEFAULT 'pending',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` enum('uploaded','received','info','access_request','access_result') NOT NULL DEFAULT 'uploaded',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `file_id`, `status`, `timestamp`, `type`) VALUES
(1, 10, 'notice uploaded successfully: Presentation Script for Slides 48-49 (1).pdf', 12, 'pending', '2025-03-24 04:30:05', 'uploaded'),
(2, 7, 'You have received a new notice: Presentation Script for Slides 48-49 (1).pdf. Please accept or deny the file.', 12, 'accepted', '2025-03-24 04:30:16', 'received'),
(3, 8, 'User Trevor_Mundo has requested access to your file: TODAY.', 10, 'approved', '2025-03-24 05:08:54', 'access_request');

-- --------------------------------------------------------

--
-- Table structure for table `deleted_users`
--

CREATE TABLE `deleted_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `position` varchar(255) NOT NULL,
  `role` enum('admin','client') NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recently_deleted`
--

CREATE TABLE `recently_deleted` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;