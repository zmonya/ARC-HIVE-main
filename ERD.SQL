-- Optimized ERD for the Arc-Hive Document Management System
-- Database: arc_hive
-- Total Tables: 15
-- Last Updated: April 09, 2025

-- Users Table (Stores admin and client data for User Management)
CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(255) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL, -- Hashed passwords
  full_name VARCHAR(255) NOT NULL,
  role ENUM('admin', 'client') NOT NULL DEFAULT 'client',
  profile_pic VARCHAR(255) DEFAULT NULL,
  position VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Deleted Users Table (Archives deleted user records for audit purposes)
CREATE TABLE deleted_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  username VARCHAR(255) NOT NULL,
  full_name VARCHAR(255) NOT NULL,
  position VARCHAR(255) NOT NULL,
  role ENUM('admin', 'client') NOT NULL,
  profile_pic VARCHAR(255) DEFAULT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Departments Table (Organizational units managed in Department Management)
CREATE TABLE departments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  type ENUM('college', 'office') NOT NULL,
  UNIQUE KEY name_type (name, type)
);

-- Sub-Departments Table (Sub-units under departments for finer organization)
CREATE TABLE sub_departments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  UNIQUE KEY department_subdept (department_id, name),
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

-- User Department Affiliations Table (Links users to departments for access control)
CREATE TABLE user_department_affiliations (
  user_id INT NOT NULL,
  department_id INT NOT NULL,
  sub_department_id INT DEFAULT NULL,
  PRIMARY KEY (user_id, department_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (sub_department_id) REFERENCES sub_departments(id) ON DELETE SET NULL
);

-- Document Types Table (Categories of documents for Document Type Management)
CREATE TABLE document_types (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(50) UNIQUE NOT NULL
);

-- Document Type Fields Table (Metadata fields for document types, used in file uploads)
CREATE TABLE document_type_fields (
  id INT PRIMARY KEY AUTO_INCREMENT,
  document_type_id INT NOT NULL,
  field_name VARCHAR(255) NOT NULL,
  field_label VARCHAR(255) NOT NULL,
  field_type ENUM('text', 'textarea', 'date') NOT NULL DEFAULT 'text',
  is_required TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY document_type_field (document_type_id, field_name),
  FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

-- Files Table (Core entity for file uploads, used in My Folder and Search All Files)
CREATE TABLE files (
  id INT PRIMARY KEY AUTO_INCREMENT,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) DEFAULT NULL, -- Null if only hard copy exists
  upload_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT DEFAULT NULL, -- Uploader, nullable if user is deleted
  file_size BIGINT DEFAULT NULL,
  file_type ENUM('pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'txt', 'zip', 'other') NOT NULL,
  hard_copy_available TINYINT DEFAULT 0,
  is_deleted TINYINT DEFAULT 0,
  deleted_at TIMESTAMP DEFAULT NULL,
  document_type_id INT NOT NULL,
  status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
  INDEX file_path_idx (file_path),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

-- File Metadata Table (Stores metadata for files, displayed in file details)
CREATE TABLE file_metadata (
  id INT PRIMARY KEY AUTO_INCREMENT,
  file_id INT NOT NULL,
  meta_key VARCHAR(255) NOT NULL,
  meta_value TEXT DEFAULT NULL,
  UNIQUE KEY file_key (file_id, meta_key),
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
);

-- Cabinets Table (Physical storage units for Physical Storage Management)
CREATE TABLE cabinets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  cabinet_name VARCHAR(255) NOT NULL,
  department_id INT NOT NULL,
  sub_department_id INT DEFAULT NULL,
  location VARCHAR(255) NOT NULL,
  layers INT NOT NULL,
  boxes INT NOT NULL,
  folders INT NOT NULL,
  folder_capacity INT NOT NULL DEFAULT 5,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  FOREIGN KEY (sub_department_id) REFERENCES sub_departments(id) ON DELETE SET NULL
);

-- Storage Locations Table (Specific slots within cabinets for storage suggestions)
CREATE TABLE storage_locations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  cabinet_id INT NOT NULL,
  layer INT NOT NULL,
  box INT NOT NULL,
  folder INT NOT NULL,
  is_occupied TINYINT DEFAULT 0,
  file_count INT NOT NULL DEFAULT 0,
  UNIQUE KEY unique_location (cabinet_id, layer, box, folder),
  FOREIGN KEY (cabinet_id) REFERENCES cabinets(id) ON DELETE CASCADE
);

-- File Storage Table (Links files to storage locations for hard copy tracking)
CREATE TABLE file_storage (
  file_id INT NOT NULL,
  storage_location_id INT NOT NULL,
  PRIMARY KEY (file_id),
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE CASCADE
);

-- File Transfers Table (Tracks file sending between users or departments)
CREATE TABLE file_transfers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  file_id INT NOT NULL,
  sender_id INT NOT NULL,
  recipient_id INT DEFAULT NULL, -- Null if sent to a department
  department_id INT DEFAULT NULL, -- Null if sent to a user
  time_sent TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  time_received TIMESTAMP DEFAULT NULL,
  time_accepted TIMESTAMP DEFAULT NULL,
  time_denied TIMESTAMP DEFAULT NULL,
  status ENUM('pending', 'accepted', 'denied') DEFAULT 'pending',
  grants_ownership TINYINT DEFAULT 0,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- Access Requests Table (Manages file access requests for non-owners)
CREATE TABLE access_requests (
  id INT PRIMARY KEY AUTO_INCREMENT,
  requester_id INT NOT NULL,
  file_id INT NOT NULL,
  owner_id INT NOT NULL,
  status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  time_requested TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  time_approved TIMESTAMP DEFAULT NULL,
  time_rejected TIMESTAMP DEFAULT NULL,
  FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notifications Table (Stores user notifications for file events)
CREATE TABLE notifications (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  message TEXT NOT NULL,
  file_id INT DEFAULT NULL,
  status ENUM('pending', 'accepted', 'denied', 'approved', 'rejected', 'completed') DEFAULT 'pending',
  timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  type ENUM('uploaded', 'received', 'info', 'access_request', 'access_result') NOT NULL DEFAULT 'uploaded',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
);