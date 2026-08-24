# YMR Marine Solutions – Dynamic Website + Full Admin Panel

## Requirements
- PHP 7.4+ (recommended 8.x)
- MySQL 5.7+ / MariaDB
- Apache / Nginx with PHP support
- `mod_rewrite` optional (not required)

## Installation Steps

### 1. Upload files
Upload the entire folder contents to your web root (or a subdirectory).

### 2. Create database
1. Open phpMyAdmin (or MySQL CLI)
2. Import the file: `install/schema.sql`
   - This creates the database `ymr_marine` and all tables with sample data.

### 3. Configure database connection
Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ymr_marine');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
```

### 4. Set permissions
Make the uploads folder writable:

```bash
chmod -R 755 assets/uploads
```

### 5. Admin Login
- URL: `https://yourdomain.com/admin/`
- Username: `admin`
- Password: `admin123`

**Change the password immediately after first login!**

To change password, run this SQL (generate a new hash with PHP `password_hash('newpass', PASSWORD_DEFAULT)`):

```sql
UPDATE admins SET password = '$2y$10$...' WHERE username = 'admin';
```

## What the Admin Panel Controls

| Section            | What you can edit                          |
|--------------------|--------------------------------------------|
| **Site Settings**  | Logo, phone, email, address, map, colors, footer text |
| **Hero**           | Title, subtitle, buttons, stats, background image |
| **About**          | Text, stats, both images, cert badge       |
| **Services**       | Full CRUD – title, description, icon, badge, featured flag, order |
| **Why Us**         | Full CRUD – title, body, icon, order       |
| **Team**           | Full CRUD – name, role, bio, photo, initials |
| **Ports**          | Full CRUD – country/region, subtext, order |
| **Testimonials**   | Full CRUD – quote, author, rating, order   |
| **Messages**       | View & delete contact form submissions     |

## Folder Structure

```
/
├── admin/                 ← Admin panel
│   ├── assets/admin.css
│   ├── includes/
│   ├── index.php          (Dashboard)
│   ├── login.php
│   ├── settings.php
│   ├── hero.php
│   ├── about.php
│   ├── services.php
│   ├── whyus.php
│   ├── team.php
│   ├── ports.php
│   ├── testimonials.php
│   └── messages.php
├── assets/
│   ├── css/style.css
│   └── uploads/           ← Uploaded images go here
├── config/database.php
├── includes/
│   ├── functions.php
│   └── header.php
├── install/schema.sql
├── index.php              ← Public website
└── process_contact.php
```

## Notes
- All public content is loaded from the database.
- Images are stored under `assets/uploads/`.
- The design is fully responsive (mobile + tablet + desktop).
- Contact form submissions are stored and visible in Admin → Messages.
- Employee Login button URL is editable from Site Settings.

