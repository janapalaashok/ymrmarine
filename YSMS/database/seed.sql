USE ysms;

INSERT INTO users(employee_number,first_name,last_name,username,password,email,mobile,address,role,date_of_joining)
VALUES
('EMP001','Admin','User','admin','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin@ymrmarine.com','9000000001','Visakhapatnam','Admin','2025-01-01'),
('EMP002','Ashok','Surveyor','surveyor','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','surveyor@ymrmarine.com','9000000002','Visakhapatnam','Surveyor','2025-02-01');

INSERT INTO vessels(vessel_name,survey_type,survey_place,agent,client,assigned_surveyor,status) VALUES
('MV CRIMSON TURACO','Bunker Survey','Visakhapatnam','ABC Shipping','Adani',2,'Pending Vessel'),
('MV STAR OCEAN','Draft Survey','Gangavaram','Oceanic','JSW',2,'Pending Vessel'),
('MV SEA PRIDE','On Hire Survey','Kakinada','Marine Agency','HPCL',2,'Pending Report'),
('MV BLUE HORIZON','Cargo Survey','Chennai','Global Marine','IOCL',2,'Completed'),
('MV EASTERN GLORY','Off Hire Survey','Paradip','Port Agency','Vedanta',2,'Completed');

INSERT INTO uploaded_files(vessel_id,excel_file,pdf_file,upload_date,remarks) VALUES
(3,'sea_pride.xlsx','sea_pride.pdf',NOW(),'Uploaded'),
(4,'blue_horizon.xlsx','blue_horizon.pdf',NOW(),'Completed');

INSERT INTO shifts(surveyor_id,shift_date,recovery,remarks) VALUES
(2,'2026-06-01',125.50,'Morning'),
(2,'2026-06-05',98.75,'Evening'),
(2,'2026-06-12',140.00,'Night'),
(2,'2026-06-20',110.25,'General');

/*
Login Credentials
Admin:
username: admin
password: password

Surveyor:
username: surveyor
password: password
*/
