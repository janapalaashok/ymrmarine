-- YMR Marine Solutions - Full Database Schema
-- Run this once in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS ymr_marine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ymr_marine;

-- Admin users
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT 'Administrator',
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default admin: username = admin , password = admin123
INSERT INTO admins (username, password, full_name, email) VALUES
('admin', '$2y$10$.BiZgX8dXWcxBMmJvCUIB.4VfjKyj6JmpYbXD/pNqEDTT6lF5Vn.C', 'Site Administrator', 'ops@ymrmarine.com');

-- Site settings (key-value for flexibility)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'YMR Marine Solutions LLP'),
('site_tagline', 'Marine Surveys Done Right.'),
('logo', 'ymr_logo.png'),
('favicon', ''),
('primary_color', '#02bbff'),
('phone', '+91 982 048 2713'),
('email', 'ops@ymrmarine.com'),
('address', 'A2605, Runwal Elegante, Lokhandwala, Andheri West, Mumbai, India-400058'),
('map_embed', 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3769.245493327357!2d72.82607257551237!3d19.140728382077274!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3be7b63d7835eeeb%3A0x4c4aec6827db0ef8!2sRunwal%20Elegante!5e0!3m2!1sen!2sin!4v1782339052372!5m2!1sen!2sin'),
('employee_login_url', 'https://ymrmarine.infinityfree.me/YSMS/'),
('footer_text', 'Your trusted partner for professional marine surveys and consultancy services across the Globe — delivering precision since 2006.'),
('copyright', '© 2026 YMR Marine Solutions LLP. All Rights Reserved.');

-- Hero section
CREATE TABLE IF NOT EXISTS hero (
    id INT PRIMARY KEY DEFAULT 1,
    eyebrow VARCHAR(150) DEFAULT 'Your Global Survey Partner',
    title VARCHAR(200) DEFAULT 'Marine Surveys Done Right.',
    title_highlight VARCHAR(50) DEFAULT 'Right.',
    subtitle TEXT,
    btn1_text VARCHAR(80) DEFAULT 'Request a Survey',
    btn1_link VARCHAR(150) DEFAULT '#contact',
    btn2_text VARCHAR(80) DEFAULT 'Our Services',
    btn2_link VARCHAR(150) DEFAULT '#services',
    bg_image VARCHAR(255) DEFAULT '',
    stat1_value VARCHAR(20) DEFAULT '18+',
    stat1_label VARCHAR(50) DEFAULT 'Years Active',
    stat2_value VARCHAR(20) DEFAULT '5000+',
    stat2_label VARCHAR(50) DEFAULT 'Surveys Done',
    stat3_value VARCHAR(20) DEFAULT '100+',
    stat3_label VARCHAR(50) DEFAULT 'Clients',
    stat4_value VARCHAR(20) DEFAULT '24/7',
    stat4_label VARCHAR(50) DEFAULT 'Availability'
);

INSERT INTO hero (id, subtitle) VALUES (1, 'YMR Marine Solutions LLP is your trusted partner for <strong>Bunker Survey</strong> and <strong>Pre-Purchase Inspection Survey</strong>, along with draft, cargo, and condition surveys across the globe — with turnaround times that keep your operations on schedule.');

-- About section
CREATE TABLE IF NOT EXISTS about (
    id INT PRIMARY KEY DEFAULT 1,
    tag VARCHAR(50) DEFAULT 'Who We Are',
    title VARCHAR(200) DEFAULT 'Trusted marine expertise since 2006',
    body TEXT,
    body2 TEXT,
    img_main VARCHAR(255) DEFAULT '',
    img_secondary VARCHAR(255) DEFAULT '',
    cert_title VARCHAR(80) DEFAULT 'Experienced Surveyors',
    cert_subtitle VARCHAR(80) DEFAULT 'Quality Assured',
    stat1_value VARCHAR(20) DEFAULT '18+',
    stat1_label VARCHAR(50) DEFAULT 'Years of experience',
    stat2_value VARCHAR(20) DEFAULT '5000+',
    stat2_label VARCHAR(50) DEFAULT 'Surveys completed',
    stat3_value VARCHAR(20) DEFAULT '100+',
    stat3_label VARCHAR(50) DEFAULT 'Clients served',
    stat4_value VARCHAR(20) DEFAULT '24/7',
    stat4_label VARCHAR(50) DEFAULT 'Operational support'
);

INSERT INTO about (id, body, body2) VALUES (1,
'We are not just another Survey solution provider in the Maritime world but as a company, we are dedicated to providing survey solutions that are not just above the market standards in terms of quality, but also at the same time equally committed to keeping our numbers sharp.

We truly believe that for all relationships to thrive, they have to be win-win for both our clients and us. With that prospective in mind, our client’s interest is always paramount for us and our communication with them is always clear and transparent.

We are just getting started, and we will continue to strive forward with all our energy and focus. 

We provide solutions for variety of Survey needs Globally in the Maritime sector and every opportunity given to us deeply valued.

Captain Gaurav Yadav the founder of this company has deep knowledge of intricacies of the Stowage plans, Bunker surveys and vessel performance. He travelled across continents on the request of clients to carry out Bunker evaluation Surveys and he is one of the best and he continues to hold the top name in the business. His ideology reflect in the was we carry out our surveys. Trust is the most important factor for businesses to grow mutually and we are totally committed to building the trust with our clients.',
'Every survey is conducted by seasoned marine professionals using calibrated equipment and internationally recognised methodologies.');

-- Services
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sort_order INT DEFAULT 0,
    code VARCHAR(10) DEFAULT 'S-01',
    title VARCHAR(120) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fa-ship',
    is_featured TINYINT(1) DEFAULT 0,
    badge VARCHAR(40) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO services (sort_order, code, title, description, icon, is_featured, badge) VALUES
(1, 'S-01', 'Bunker Survey', 'Accurate measurement and verification of bunker fuel quantities during bunkering operations, on-hire/off-hire, and consumption monitoring — trusted Bunker Survey specialists across India''s East Coast ports.', 'fa-gas-pump', 1, 'Most Requested'),
(2, 'S-02', 'Pre-Purchase Inspection Survey', 'Thorough technical and commercial due diligence for vessel acquisitions — our Pre-Purchase Inspection Survey protects buyers with an independent, objective condition assessment.', 'fa-search-dollar', 1, 'Most Requested'),
(3, 'S-03', 'Draft Survey', 'Precise determination of cargo weight through systematic vessel draft readings — the industry-standard method for bulk commodity weighing.', 'fa-ruler-combined', 0, NULL),
(4, 'S-04', 'Cargo Survey', 'Detailed inspection, tally, and condition assessment of all cargo types — bulk, breakbulk, containers, and project cargo — at load and discharge.', 'fa-boxes', 0, NULL),
(5, 'S-05', 'Condition Survey', 'Comprehensive assessment of hull, machinery, and equipment condition — for P&I, insurance, or vessel acquisition purposes.', 'fa-ship', 0, NULL),
(6, 'S-06', 'On-Hire / Off-Hire', 'Detailed condition and bunker surveys marking charter commencement and termination — protecting both owners and charterers from disputes.', 'fa-exchange-alt', 0, NULL);

-- Why Us
CREATE TABLE IF NOT EXISTS why_us (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sort_order INT DEFAULT 0,
    title VARCHAR(100) NOT NULL,
    body TEXT,
    icon VARCHAR(50) DEFAULT 'fa-check',
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO why_us (sort_order, title, body, icon) VALUES
(1, 'Measurement Accuracy', 'Calibrated instruments, verified procedures, and double-checked calculations mean our figures stand up to dispute — every time.', 'fa-chart-line'),
(2, 'Seasoned Professionals', 'Our surveyors carry deep sea, port, and terminal experience. They know what to look for before the paperwork begins.', 'fa-user-shield'),
(3, 'Fast Turnaround', 'Draft reports delivered within hours of survey completion. Laytime doesn''t wait — neither do we.', 'fa-bolt'),
(4, 'International Standards', 'Every survey follows IMO, SOLAS, ASTM, and ISO guidelines so your counterparty in London or Singapore can rely on our reports without question.', 'fa-globe-asia'),
(5, 'Independent & Transparent', 'We work for our clients, not for the cargo or the vessel. Our neutrality is non-negotiable.', 'fa-handshake'),
(6, '24 / 7 Availability', 'Ships don''t keep business hours. Our team is reachable around the clock for urgent survey requests and on-site mobilisation.', 'fa-headset');

-- Team
CREATE TABLE IF NOT EXISTS team (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sort_order INT DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(100),
    bio TEXT,
    avatar_initials VARCHAR(5) DEFAULT 'YM',
    photo VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO team (sort_order, name, role, bio, avatar_initials) VALUES
(1, 'Capt. Gaurav Yadav', 'Director', 'Leads YMR''s survey operations with over two decades of seafaring and marine consultancy experience.', 'GY'),
(2, 'Apurva Dandekar', 'Global Survey Coordinator', 'Coordinates bunker, draft and cargo survey assignments across the Globe, end to end.', 'AD'),
(3, 'Prakash Bommali', 'Survey Coordinator', 'Manages scheduling, client communication, and onboard coordination for every survey job.', 'PB'),
(4, 'Ashok Janapala', 'Surveyor & IT Admin', 'Keeps YMR''s survey management systems, reports, and digital infrastructure running smoothly.', 'AJ');

-- Ports / Coverage
CREATE TABLE IF NOT EXISTS ports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sort_order INT DEFAULT 0,
    name VARCHAR(80) NOT NULL,
    state VARCHAR(80) DEFAULT 'All Ports',
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO ports (sort_order, name, state) VALUES
(1, 'India', 'All Ports'),
(2, 'Malaysia', 'All Ports'),
(3, 'Philippines', 'All Ports'),
(4, 'Sri Lanka', 'All Ports'),
(5, 'Bangladesh', 'All Ports'),
(6, 'Indonesia', 'All Ports'),
(7, 'Middle East', 'Dubai, Oman, Qatar'),
(8, 'China', 'All Ports'),
(9, 'Africa', 'All Ports'),
(10, 'Singapore', 'All Ports'),
(11, 'Australia', 'All Ports'),
(12, 'USA', 'All Ports');

-- Testimonials
CREATE TABLE IF NOT EXISTS testimonials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sort_order INT DEFAULT 0,
    quote TEXT NOT NULL,
    author_name VARCHAR(100),
    author_role VARCHAR(150),
    avatar_initials VARCHAR(5) DEFAULT 'CL',
    rating DECIMAL(2,1) DEFAULT 5.0,
    is_active TINYINT(1) DEFAULT 1
);

INSERT INTO testimonials (sort_order, quote, author_name, author_role, avatar_initials, rating) VALUES
(1, '"YMR Marine provided exceptional service during our recent bunker survey in Visakhapatnam. Their professionalism and accuracy were outstanding — report was in our hands within four hours of completion."', 'Mr. Sathisha Setty', 'Operation Manager, Opulence Voyage', 'SS', 5.0),
(2, '"Fast turnaround and a highly detailed draft survey report. We''ve been using YMR as our go-to partner for all East Coast surveys for three years. Consistent, reliable, no surprises."', 'Kodanda Sriram', 'Vessel Operator, Aequor Shipping', 'KR', 5.0),
(3, '"The pre-purchase inspection saved us from a costly mistake. YMR''s surveyor flagged structural concerns our own team had missed. Invaluable service."', 'Arun Kumar', 'Director, Sunrise Maritime', 'AK', 5.0),
(4, '"We rely on YMR for all our bunker surveys at India & Malaysia. Their team understands the pressures of port operations and never causes delays."', 'Suresh Venkat', 'Chartering Manager, Indo Bulk Carriers', 'SV', 4.5);

-- Contact form submissions (optional log)
CREATE TABLE IF NOT EXISTS contact_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    company VARCHAR(150),
    email VARCHAR(150),
    phone VARCHAR(40),
    service VARCHAR(100),
    port VARCHAR(100),
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) DEFAULT 0
);
