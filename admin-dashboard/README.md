# Smart Door Pro - Admin Dashboard

**Technology:** React 18 + TypeScript + Tailwind CSS  
**Build Tool:** Vite  
**State Management:** Context API  

---

## Installation

```bash
cd admin-dashboard
npm install
npm run dev
```

Open: `http://localhost:3000`

---

## Features

### 🔐 Admin Login
- Email & Password authentication
- Session management with JWT tokens
- Refresh token rotation
- Secure cookie storage

### 🎮 Door Control
- Real-time door status (Online/Offline)
- **One-click door opening**
- **Door closing**
- Configurable unlock duration
- Lock/Unlock command history

### 📱 QR Code Generator
- Generate guest passes
- Configure usage limits
- Set expiration time
- Download/Print QR codes
- Display pass details

### 👥 User Management
- Add/Edit/Delete users
- Role assignment (Admin, User, Guest)
- Access rule configuration
- Daily usage limits
- Suspension/Resume users

### ⚙️ Device Settings
- Configure door name
- Set unlock duration
- Choose relay mode (Active High/Low)
- WiFi network selection
- Operating mode selection (Local/Server/Telegram)

### 📊 Access Logs
- View all access events
- Filter by date/user/status
- Export logs to CSV
- Real-time activity feed

---

## Admin Default Credentials

```
Email: admin@smartdoor.com
Password: admin123456
```

⚠️ **IMPORTANT:** Change password immediately after first login!

---

## Key Pages

### Login Page (`/login`)
- Email input
- Password input with toggle
- Error handling
- Loading state
- Demo credentials display

### Dashboard (`/dashboard`)
- **Control Tab:** Door open/close with real-time status
- **QR Tab:** Generate and manage guest passes
- **Users Tab:** Manage system users
- **Settings Tab:** Configure device parameters
- **Logs Tab:** View access history

---

## API Integration

All API calls go through `/api/v1` endpoints:

```typescript
// Authentication
POST /auth/login
POST /auth/refresh
GET /me

// Door Control
GET /door/status
POST /door/open
PATCH /door/settings

// Guest Passes
GET /passes
POST /passes
GET /passes/{id}

// Users
GET /users
POST /users
PATCH /users/{id}
DELETE /users/{id}

// Device
GET /device/status
POST /device/settings
GET /device/logs
```

---

## RTL Support

- Full Arabic (RTL) support
- Tailwind RTL classes
- Responsive design for all screen sizes
- Mobile-optimized interface

---

## Security Features

- ✅ JWT authentication
- ✅ HTTP-only cookies
- ✅ CSRF protection
- ✅ Input validation
- ✅ Error boundary
- ✅ Session timeout
- ✅ Protected routes

---

**Status:** Development  
**Version:** 1.0.0
