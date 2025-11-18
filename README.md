# Barangay Document Issuance System (BDIS)

## Setup Instructions

1. **Install XAMPP**: Make sure XAMPP is installed and running (Apache and MySQL).

2. **Database Setup**:
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Create a new database named `bdis`
   - Import the `database/schema.sql` file to create tables and initial data

3. **Access the System**:
   - Open your browser and go to `http://localhost/Project_A2`
   - Register as a resident or login with existing credentials

## Default Users

After importing the schema, you can create users manually in phpMyAdmin or use the registration form.

## Features Implemented

- Role-based access (Resident, Secretary, Captain, Admin)
- Document request system
- User verification
- Document status management
- Basic chat support
- Activity logging
- Responsive UI with Bootstrap

## Technologies Used

- PHP 8+
- MySQL
- HTML5, CSS3, JavaScript
- Bootstrap 5

## File Structure

```
Project_A2/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── database/
│   └── schema.sql
├── includes/
│   └── config.php
├── pages/
│   ├── admin/
│   ├── secretary/
│   ├── captain/
│   └── resident/
├── index.php
├── login.php
├── register.php
├── logout.php
└── unauthorized.php
```

## Security Features

- Password hashing with password_hash()
- Prepared statements to prevent SQL injection
- Session-based authentication
- Role-based access control

## Future Enhancements

- Real-time notifications
- PDF document generation
- File uploads for templates
- Advanced chat features
- Email notifications
- Dark/Light mode toggle

Enjoy using the BDIS system!
