# Setup Default Avatar Instructions

## Step 1: Save the Default Avatar Image

1. Save the provided default avatar image (the gray circular profile icon) as `default-avatar.png`
2. Place it in the folder: `c:\xampp\htdocs\Project_A2\uploads\default-avatar.png`

## Step 2: What Has Been Updated

The following changes have been made to automatically assign the default profile picture to all new user accounts:

### File: `verify_otp.php`
- Modified the user creation INSERT statement to include `'default-avatar.png'` as the default profile picture
- Every new user account will now automatically have this profile picture assigned

### How It Works

When a new user registers:
1. They complete the registration form
2. They verify their email with OTP
3. The system creates their account with:
   - All their personal information
   - A default profile picture: `default-avatar.png`
   - Default role: 'resident'
   - Pending verification status

### Fallback Display

The header files already have fallback logic:
- If a user has a profile picture: displays the image from `/uploads/`
- If NO profile picture: displays a Bootstrap icon placeholder

## Step 3: Verify Installation

After placing the image in the uploads folder, test by:
1. Creating a new user account
2. Logging in
3. Check if the profile picture appears in the header

## Notes

- The image should be a square PNG file for best results
- Recommended size: 200x200 pixels or larger
- The system will automatically resize it to fit the circular display (40px diameter in header)
- Users can later update their profile picture through their profile settings
