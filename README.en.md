# MPay V2 Webman

A payment system backend management API developed based on the Webman framework.

## Project Overview

MPay V2 Webman is a payment system backend management API built with the high-performance PHP framework Webman, providing core functionalities such as user authentication, permission management, system configuration, and menu routing.

## Technology Stack

- **Backend Framework**: Webman
- **PHP Version**: PHP 8.0+
- **Database**: MySQL
- **Cache**: Redis
- **Authentication**: JWT

## Project Structure

```
app/
├── command/          # Command-line controllers
├── common/           # Common base classes
│   ├── base/         # Base classes (Controller, Model, Service, Repository)
│   ├── constants/    # Constant definitions
│   ├── enums/        # Enum classes
│   ├── middleware/   # Middleware
│   └── utils/        # Utility classes
├── events/           # Event classes
├── exceptions/       # Exception classes
├── http/             # HTTP controllers
│   └── admin/        # Backend management APIs
│       ├── controller/  # Controllers
│       └── middleware/  # Middleware
├── models/           # Data models
├── process/          # Process management
├── repositories/     # Data repositories
├── services/         # Business services
└── validation/       # Validators
config/               # Configuration files
database/             # Database scripts
doc/                  # Documentation
public/               # Public resources
support/              # Framework support classes
```

## Core Features

### Authentication Module
- User login/logout
- CAPTCHA generation and verification
- JWT token authentication
- User information retrieval

### Permission Management
- Menu and route management
- User-role association

### System Configuration
- System parameter configuration
- Dictionary management
- Form configuration
- Cache management for configurations

### Middleware
- CORS cross-origin handling
- Static file handling
- Authentication and authorization

## Quick Start

### System Requirements

- PHP >= 8.0
- Composer
- Redis
- MySQL >= 5.7

### Installation Steps

1. Clone the project
```bash
git clone https://gitee.com/technical-laohu/mpay_v2_webman.git
cd mpay_v2_webman
```

2. Install dependencies
```bash
composer install
```

3. Configure the database
Edit `config/database.php` to set up your database connection details.

4. Configure Redis
Edit `config/redis.php` to set up your Redis connection details.

5. Import the database
```bash
mysql -uusername -p database_name < database/ma_system_config.sql
```

6. Start the server
```bash
# Linux/Mac
php start.php start

# Windows
windows.bat
```

## API Endpoints

### Authentication API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/auth/captcha` | GET | Get CAPTCHA |
| `/admin/auth/login` | POST | User login |

### User API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/user/info` | GET | Get current user information |

### Menu API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/menu/routers` | GET | Get menu routes |

### System API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/admin/system/dict/{code}` | GET | Get dictionary data |
| `/admin/system/tabs` | GET | Get tab configuration |
| `/admin/system/config/{tabKey}` | GET/POST | Get/submit form configuration |

## Exception Handling

The project defines the following custom exception classes:

- `BadRequestException` - Invalid request parameters (400)
- `UnauthorizedException` - Unauthorized access (401)
- `ForbiddenException` - Access forbidden (403)
- `NotFoundException` - Resource not found (404)
- `ValidationException` - Parameter validation failed (422)
- `InternalServerException` - Internal server error (500)

## Configuration Reference

Main configuration files are located in the `config/` directory:

- `app.php` - Application configuration
- `database.php` - Database configuration
- `redis.php` - Redis configuration
- `jwt.php` - JWT configuration
- `route.php` - Routing configuration
- `middleware.php` - Middleware configuration
- `cache.php` - Cache configuration
- `log.php` - Log configuration

## License

This project is open-sourced under the MIT License.