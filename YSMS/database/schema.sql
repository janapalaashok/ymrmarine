CREATE DATABASE IF NOT EXISTS ysms;
USE ysms;

CREATE TABLE users(
 id INT AUTO_INCREMENT PRIMARY KEY,
 employee_number VARCHAR(20) NOT NULL,
 first_name VARCHAR(50),
 last_name VARCHAR(50),
 username VARCHAR(50) UNIQUE,
 password VARCHAR(255),
 email VARCHAR(100),
 mobile VARCHAR(20),
 address TEXT,
 role ENUM('Admin','Surveyor') NOT NULL,
 date_of_joining DATE,
 profile_photo VARCHAR(255),
 status ENUM('Active','Inactive') DEFAULT 'Active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE vessels(
 id INT AUTO_INCREMENT PRIMARY KEY,
 vessel_name VARCHAR(150),
 survey_type VARCHAR(100),
 survey_place VARCHAR(100),
 agent VARCHAR(100),
 client VARCHAR(100),
 assigned_surveyor INT,
 status ENUM('Pending Vessel','Pending Report','Completed') DEFAULT 'Pending Vessel',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_surveyor FOREIGN KEY (assigned_surveyor) REFERENCES users(id)
);

CREATE TABLE uploaded_files(
 id INT AUTO_INCREMENT PRIMARY KEY,
 vessel_id INT,
 excel_file VARCHAR(255),
 pdf_file VARCHAR(255),
 upload_date DATETIME,
 remarks TEXT,
 CONSTRAINT fk_vessel FOREIGN KEY(vessel_id) REFERENCES vessels(id)
);

CREATE TABLE shifts(
 id INT AUTO_INCREMENT PRIMARY KEY,
 surveyor_id INT,
 shift_date DATE,
 recovery DECIMAL(10,2),
 remarks TEXT,
 CONSTRAINT fk_shift_user FOREIGN KEY(surveyor_id) REFERENCES users(id)
);
