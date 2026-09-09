-- Adds the Super Admin role. Purely additive — existing Admin/Surveyor/Client
-- roles and all role_id-based logic (role_id = 1 Admin, 2 Surveyor) are untouched.
-- Create a Super Admin user afterwards via:
--   INSERT INTO users (role_id, username, password, full_name, status)
--   VALUES ((SELECT id FROM roles WHERE name='Super Admin'), 'superadmin', '<bcrypt-hash>', 'Super Admin', 'Active');

INSERT INTO `roles` (`name`)
SELECT 'Super Admin' WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = 'Super Admin');
